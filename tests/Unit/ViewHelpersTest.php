<?php

declare(strict_types=1);

namespace ConciliaRH\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Helpers de presentación (docs/DESIGN_SYSTEM.md §11–14): dinero sin float, diferencias que no dependen del color,
 * distintivos de estado con icono + texto + forma, y errores de campo enlazados. Todo valor externo sale escapado.
 */
final class ViewHelpersTest extends TestCase
{
    public function testMoneyFormatsTextNumericWithoutFloat(): void
    {
        self::assertSame('<span class="money">$1,234,567.89</span>', money('1234567.89'));
        self::assertSame('<span class="money money-neg">−$500.00</span>', money('-500'), 'Signo menos tipográfico, no guion');
        self::assertSame('<span class="money">$0.00</span>', money('0'));
        self::assertSame('<span class="money">$0.00</span>', money('-0.001'), 'Un negativo que redondea a cero no lleva signo');
        self::assertSame('<span class="money">$1.01</span>', money('1.005'), 'Redondeo half-up con bcmath');
        self::assertSame('<span class="money">$90,071,992,547,409.93</span>', money('90071992547409.93'), 'Sin pérdida de precisión de float');
        self::assertSame('<span class="money diff--none">—</span>', money(null));
        self::assertSame('<span class="money diff--none">—</span>', money(''));
        self::assertSame('<span class="money">$1,235</span>', money('1234.5', 0));
    }

    public function testMoneySignedAndPlain(): void
    {
        self::assertSame('+$12.50', money_plain('12.5', 2, true));
        self::assertSame('−$12.50', money_plain('-12.5', 2, true));
        self::assertSame('$0.00', money_plain('0', 2, true), 'El cero no lleva signo');
        self::assertSame('—', money_plain(null));
        self::assertSame('<span class="money">+$3.00</span>', money('3', 2, true));
    }

    public function testMoneyEscapesNonNumericInput(): void
    {
        $out = money('<script>alert(1)</script>');
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
        self::assertSame('<b>', money_plain('<b>'), 'money_plain devuelve texto: el llamador escapa con e()');
    }

    public function testMoneyDiffNeverDependsOnColorOnly(): void
    {
        $under = money_diff('-3500', '0.01');
        self::assertStringContainsString('diff--under', $under);
        self::assertStringContainsString('bi-caret-down-fill', $under, 'Flecha abajo');
        self::assertStringContainsString('−$3,500.00', $under, 'Signo explícito');
        self::assertStringContainsString('(pagado de menos)</span>', $under, 'Texto para lectores de pantalla');
        self::assertStringContainsString('title="Pagado de menos"', $under);

        $over = money_diff('1200.00', '0.01');
        self::assertStringContainsString('diff--over', $over);
        self::assertStringContainsString('bi-caret-up-fill', $over);
        self::assertStringContainsString('+$1,200.00', $over);
        self::assertStringContainsString('(pagado de más)', $over);

        self::assertStringContainsString('$0.00', money_diff('0.00', '0.01'));
        self::assertStringContainsString('(sin diferencia)', money_diff('0.00', '0.01'));
        $within = money_diff('-0.01', '0.01');
        self::assertStringContainsString('diff--even', $within, 'Dentro de la tolerancia');
        self::assertStringContainsString('±$0.01', $within);
        self::assertStringContainsString('diff--under', money_diff('-0.02', '0.01'), 'Fuera de la tolerancia por un centavo');
        self::assertStringContainsString('diff--under', money_diff('-0.01', 'x'), 'Tolerancia inválida ⇒ 0');
        self::assertSame('<span class="diff diff--none">—</span>', money_diff(null));
    }

    public function testMoneyDiffEscapesUnexpectedValues(): void
    {
        $out = money_diff('"><img src=x onerror=alert(1)>');
        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('&quot;&gt;&lt;img', $out);
    }

    public function testStatusBadgeHasIconTextTitleAndEscapesLabel(): void
    {
        $b = status_badge('PAGO_MENOR');
        self::assertSame('<span class="status status--danger" title="Pago menor"><i class="bi bi-arrow-down-circle-fill" aria-hidden="true"></i>Pago menor</span>', $b);
        $x = status_badge('CORRECTO', '<script>x</script>"');
        self::assertStringNotContainsString('<script>', $x);
        self::assertStringContainsString('title="&lt;script&gt;x&lt;/script&gt;&quot;"', $x, 'El atributo title también se escapa');
        $unknown = status_badge('<b>ESTADO</b>');
        self::assertStringContainsString('status--neutral', $unknown);
        self::assertStringContainsString('&lt;b&gt;ESTADO&lt;/b&gt;', $unknown);
    }

    /** Los estados críticos se distinguen por icono, texto y FORMA del distintivo; no sólo por color (§14). */
    public function testCriticalStatesAreDistinguishableWithoutColor(): void
    {
        $states = ['CORRECTO', 'ERROR', 'PENDIENTE', 'PENDIENTE_IDENTIDAD', 'REVISION_MANUAL', 'EN_REVISION', 'NO_PAGADO', 'PAGO_MENOR', 'PAGO_MAYOR', 'CERRADA', 'NO_OFICIAL', 'OFICIAL'];
        $signatures = [];
        foreach ($states as $s) {
            [$variant, $icon, $text] = status_meta($s);
            self::assertStringStartsWith('bi-', $icon, $s);
            self::assertNotSame('', trim($text), $s);
            self::assertNotSame($s, $text, "$s tiene texto legible propio");
            $signatures[$icon . '|' . $text] = $s;
        }
        self::assertCount(count($states), $signatures, 'Cada estado tiene una combinación única de icono y texto');
        self::assertSame('review', status_meta('REVISION_MANUAL')[0], 'Revisión: contorno (forma), no sólo color');
        self::assertSame('unofficial', status_meta('NO_OFICIAL')[0], 'Provisional: contorno punteado');
        self::assertSame('wine', status_meta('CERRADA')[0], 'Definitivo: sólido');
    }

    /** Toda variante que usa el mapa de estados existe en la hoja de componentes (evita distintivos sin estilo). */
    public function testEveryStatusVariantHasAStyle(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components.css');
        $map = (new \ReflectionFunction('status_meta'))->getStaticVariables()['map'] ?? null;
        if ($map === null) {
            status_meta('CORRECTO'); // inicializa la variable estática
            $map = (new \ReflectionFunction('status_meta'))->getStaticVariables()['map'];
        }
        foreach (array_unique(array_column($map, 0)) as $variant) {
            self::assertMatchesRegularExpression('/\.status--' . preg_quote($variant, '/') . '\b/', $css, "Falta .status--$variant");
        }
        foreach ($map as $state => [, $icon]) {
            self::assertMatchesRegularExpression('/^bi-[a-z0-9-]+$/', $icon, "Icono inválido en $state");
        }
    }

    public function testFieldErrorIsLinkedAndEscaped(): void
    {
        self::assertSame('', field_error('anio', null));
        self::assertSame('', field_invalid('anio', ''));
        self::assertSame('<div class="invalid-feedback" id="anio-error">Año &lt;inválido&gt;</div>', field_error('anio', 'Año <inválido>'));
        self::assertSame(' aria-invalid="true" aria-describedby="anio-error"', field_invalid('anio', 'x'));
        self::assertStringContainsString('id="a&quot;b-error"', field_error('a"b', 'x'), 'El id también se escapa');
    }
}
