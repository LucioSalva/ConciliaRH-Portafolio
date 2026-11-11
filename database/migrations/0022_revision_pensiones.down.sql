-- Revierte 0022 (sólo desarrollo/pruebas)
DROP FUNCTION IF EXISTS fn_historial_clabe(bigint);
DROP FUNCTION IF EXISTS fn_revision_dejar_pendiente(bigint, integer, bigint, text);
DROP TABLE IF EXISTS revision_pendientes;
