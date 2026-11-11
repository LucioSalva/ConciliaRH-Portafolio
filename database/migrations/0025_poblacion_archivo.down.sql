-- 0025 (down) — Retira la población declarada del archivo (sólo desarrollo/pruebas).
REVOKE INSERT (poblacion), UPDATE (poblacion) ON archivos_importados FROM conciliarh_web;
ALTER TABLE archivos_importados DROP CONSTRAINT archivos_poblacion;
ALTER TABLE archivos_importados DROP COLUMN poblacion;
