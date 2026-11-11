<?php

declare(strict_types=1);

namespace ConciliaRH\Engine\Workbook;

use Generator;
use XMLReader;

final class XmlEvents
{
    private int $events = 0;

    /** @param callable():void $tick */
    public function __construct(private readonly string $zipPath, private readonly int $maxXml, private $tick, private readonly int $tickEvery = 20_000)
    {
    }

    /** @return Generator<int,array{0:string,1:string,2:array<string,string>,3:?string}> */
    public function parse(string $part, int $size): Generator
    {
        if ($size > $this->maxXml) {
            throw new AnalysisError('CONTENEDOR_PELIGROSO', 'Una parte XML excede el tamaño permitido.');
        }
        $r = new XMLReader();
        if (!@$r->open('zip://' . $this->zipPath . '#' . $part, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'Una parte del libro no se puede leer.');
        }
        $stack = [];
        try {
            while (true) {
                $ok = @$r->read();
                if (!$ok) {
                    $err = libxml_get_last_error();
                    libxml_clear_errors();
                    if ($err !== false && $stack !== []) {
                        throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'Una parte XML del libro está dañada.');
                    }
                    break;
                }
                switch ($r->nodeType) {
                    case XMLReader::DOC_TYPE:
                        throw new AnalysisError('ESTRUCTURA_ILEGIBLE', 'El XML declara una DTD (no permitido).');
                    case XMLReader::ELEMENT:
                        $this->count();
                        $attrs = [];
                        if ($r->hasAttributes) {
                            while ($r->moveToNextAttribute()) {
                                if ($r->prefix !== 'xmlns' && $r->name !== 'xmlns') {
                                    $attrs[$r->localName] = $r->value;
                                }
                            }
                            $r->moveToElement();
                        }
                        $name = $r->localName;
                        $empty = $r->isEmptyElement;
                        if ($stack !== []) {
                            $stack[count($stack) - 1][3] = true;
                        }
                        yield ['start', $name, $attrs, null];
                        if ($empty) {
                            $this->count();
                            yield ['end', $name, $attrs, null];
                        } else {
                            $stack[] = [$name, $attrs, null, false];
                        }
                        break;
                    case XMLReader::TEXT:
                    case XMLReader::CDATA:
                    case XMLReader::WHITESPACE:
                    case XMLReader::SIGNIFICANT_WHITESPACE:
                        $top = count($stack) - 1;
                        if ($top >= 0 && !$stack[$top][3]) {
                            $stack[$top][2] = ($stack[$top][2] ?? '') . $r->value;
                        }
                        break;
                    case XMLReader::END_ELEMENT:
                        $this->count();
                        [$name, $attrs, $text] = array_pop($stack);
                        yield ['end', $name, $attrs, $text];
                        break;
                }
            }
        } finally {
            $r->close();
        }
    }

    private function count(): void
    {
        $this->events++;
        if ($this->events % $this->tickEvery === 0) {
            ($this->tick)();
        }
    }
}
