-- =====================================================================
-- 0025 — Población del archivo (Policías / Bomberos) declarada por el espacio de carga de la quincena.
-- Los archivos interbancarios no traen número de empleado: sin este dato el cotejo no sabe a qué
-- población pertenece su total. NULL = archivo cargado antes de esta versión (el motor la deduce como antes).
-- =====================================================================
ALTER TABLE archivos_importados ADD COLUMN poblacion text;
ALTER TABLE archivos_importados ADD CONSTRAINT archivos_poblacion CHECK (poblacion IS NULL OR poblacion IN ('POLICIA', 'BOMBEROS'));

GRANT INSERT (poblacion), UPDATE (poblacion) ON archivos_importados TO conciliarh_web;
