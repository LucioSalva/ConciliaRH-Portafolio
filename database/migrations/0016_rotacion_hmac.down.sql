-- Revierte 0016 (sólo desarrollo/pruebas). Si ya se completó una rotación, las huellas quedan con la llave destino:
-- la configuración debe apuntar ACCOUNT_HMAC_KEY a esa llave antes de revertir.
DROP TRIGGER IF EXISTS hmac_llaves_estado_reglas ON hmac_llaves_estado;
DROP FUNCTION IF EXISTS fn_hmac_estado_reglas();
DROP TRIGGER IF EXISTS cuentas_bancarias_hmac_origen ON cuentas_bancarias;
DROP FUNCTION IF EXISTS fn_cuenta_hmac_origen();
DROP TRIGGER IF EXISTS staging_registros_hmac ON staging_registros;
DROP TRIGGER IF EXISTS registros_tesoreria_hmac ON registros_tesoreria;
DROP FUNCTION IF EXISTS fn_hmac_huella_reglas();
CREATE OR REPLACE FUNCTION fn_cuenta_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    -- La identidad de la cuenta no cambia; la re-encriptación (rotación de llave) sí puede cambiar
    -- el texto cifrado y el key_id, pero nunca el HMAC, el dueño ni el uso.
    IF NEW.empleado_id <> OLD.empleado_id OR NEW.uso <> OLD.uso OR NEW.cuenta_hmac <> OLD.cuenta_hmac
       OR NEW.origen <> OLD.origen OR NEW.origen_registro_id <> OLD.origen_registro_id THEN
        RAISE EXCEPTION 'La identidad de una cuenta registrada es inmutable' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.estado = 'INACTIVA' AND NEW.estado = 'ACTIVA' THEN
        RAISE EXCEPTION 'Una cuenta desactivada no se reactiva: confirme de nuevo' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;
DROP INDEX IF EXISTS cuentas_bancarias_activa_nueva_uk;
DROP INDEX IF EXISTS registros_tesoreria_hmac_pend_idx;
DROP INDEX IF EXISTS staging_registros_hmac_pend_idx;
ALTER TABLE staging_registros DROP CONSTRAINT IF EXISTS staging_hmac_key, DROP COLUMN IF EXISTS cuenta_hmac_key_id, DROP COLUMN IF EXISTS cuenta_hmac_nueva;
ALTER TABLE registros_tesoreria DROP CONSTRAINT IF EXISTS tes_hmac_key, DROP COLUMN IF EXISTS cuenta_hmac_key_id, DROP COLUMN IF EXISTS cuenta_hmac_nueva;
ALTER TABLE cuentas_bancarias DROP CONSTRAINT IF EXISTS cuentas_hmac_key, DROP COLUMN IF EXISTS hmac_key_id, DROP COLUMN IF EXISTS cuenta_hmac_nueva;
ALTER TABLE analisis_archivo DROP CONSTRAINT IF EXISTS analisis_hash_key, DROP COLUMN IF EXISTS hash_key_id;
ALTER TABLE workers DROP COLUMN IF EXISTS hmac_key_ids;
DROP FUNCTION IF EXISTS fn_hmac_verificar_kcv(text, text);
DROP FUNCTION IF EXISTS fn_hmac_key_activa();
DROP TABLE IF EXISTS hmac_rotaciones;
DROP TABLE IF EXISTS hmac_llaves_estado;
