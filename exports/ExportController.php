<?php
/**
 * exports/ExportController.php
 * Genera reportes CSV descargables
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/core/Database.php';

class ExportController
{
    public static function export(string $tipo): never
    {
        match($tipo) {
            'comedor'    => self::exportComedor(),
            'asistencia' => self::exportAsistencia(),
            default      => Response::error("Tipo de exportación no válido: $tipo"),
        };
    }

    private static function csvHeaders(string $filename): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        // BOM para que Excel lo abra correctamente con tildes
        echo "\xEF\xBB\xBF";
    }

    private static function exportComedor(): never
    {
        $desde   = $_GET['desde']      ?? date('Y-m-d');
        $hasta   = $_GET['hasta']      ?? date('Y-m-d');
        $area    = $_GET['area']       ?? null;
        $trabId  = $_GET['trabajador'] ?? null;
        $tipo    = $_GET['tipo']       ?? null;
        $persona = $_GET['persona']    ?? 'todos'; // todos|trabajador|visitante

        $rows = [];

        // ── 1. Trabajadores (Leen de eventos_personal) ──────────────────────
        if ($persona !== 'visitante') {
            // ⚠️ CORRECCIÓN: Se eliminó ep.tipo_persona de la cláusula WHERE
            $sql = "SELECT DATE(ep.fecha_hora) AS fecha, TIME(ep.fecha_hora) AS hora,
                           ep.tipo_evento, 'TRABAJADOR' AS tipo_persona,
                           t.nombre_completo AS nombre, t.dni,
                           a.nombre_area, t.cargo, t.empresa
                    FROM eventos_personal ep
                    JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
                    JOIN areas a ON a.id_area = t.id_area
                    WHERE ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                      AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
            $params = [$desde, $hasta];
            if ($area)   { $sql .= " AND t.id_area = ?";        $params[] = $area; }
            if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $params[] = $trabId; }
            if ($tipo)   { $sql .= " AND ep.tipo_evento = ?";   $params[] = $tipo; }
            $rows = array_merge($rows, Database::fetchAll($sql, $params));
        }

        // ── 2. Visitantes (Leen de tu TABLA REAL: consumo_visitantes) ────────
        if ($persona !== 'trabajador') {
            // ⚠️ CORRECCIÓN: Apunta a consumo_visitantes, quitado v.dni y ep.tipo_persona
            $sql = "SELECT DATE(cv.fecha_hora) AS fecha, TIME(cv.fecha_hora) AS hora,
                           cv.tipo_comida AS tipo_evento, 'VISITANTE' AS tipo_persona,
                           v.nombre, '' AS dni,
                           'Visitante' AS nombre_area, '' AS cargo, v.empresa
                    FROM consumo_visitantes cv
                    JOIN visitantes v ON v.id_visitante = cv.id_visitante
                    WHERE DATE(cv.fecha_hora) BETWEEN ? AND ?";
            $params = [$desde, $hasta];
            if ($tipo) { $sql .= " AND cv.tipo_comida = ?"; $params[] = $tipo; }
            $rows = array_merge($rows, Database::fetchAll($sql, $params));
        }

        // Ordenar de más antiguo a más reciente para el reporte unificado
        usort($rows, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));

        self::csvHeaders("comedor_{$desde}_{$hasta}.csv");

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Fecha','Hora','Tipo Comida','Tipo Persona','Nombre','DNI','Área','Cargo','Empresa'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['fecha'], $r['hora'], $r['tipo_evento'], $r['tipo_persona'],
                $r['nombre'], $r['dni'], $r['nombre_area'], $r['cargo'], $r['empresa'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    private static function exportAsistencia(): never
    {
        $desde  = $_GET['desde'] ?? date('Y-m-d');
        $hasta  = $_GET['hasta'] ?? date('Y-m-d');
        $area   = $_GET['area']   ?? null;
        $trabId = $_GET['trabajador'] ?? null;

        $sql = "SELECT 
                    DATE(ep.fecha_hora) AS fecha,
                    t.nombre_completo,
                    t.dni,
                    a.nombre_area,
                    t.cargo,
                    t.empresa,
                    TIME(MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END)) AS hora_ingreso,
                    TIME(MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END)) AS salida_break,
                    TIME(MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)) AS ingreso_break,
                    TIME(MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)) AS salida_trabajo,
                    
                    -- Horas netas calculadas
                    ROUND((IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)), 0) - IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)), 0)) / 60, 2) AS horas_netas,
                    
                    -- Horas programadas del turno rotativo
                    11.00 AS horas_programadas,
                    
                    -- Diferencia
                    ROUND(((IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)), 0) - IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)), 0)) / 60) - 11.00, 2) AS diferencia,
                    
                    -- Tipo de diferencia
                    CASE WHEN ((IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)), 0) - IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END), MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)), 0)) / 60) >= 11.00 THEN 'Extra' ELSE 'Deficitaria' END AS tipo_diferencia
                    
                FROM eventos_personal ep
                JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
                JOIN areas a ON a.id_area = t.id_area
                WHERE ep.tipo_evento IN ('INGRESO', 'SALIDA_BREAK', 'REGRESO_BREAK', 'SALIDA_TRABAJO')
                  AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
                
        $params = [$desde, $hasta];
        if ($area)   { $sql .= " AND t.id_area = ?"; $params[] = $area; }
        if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $params[] = $trabId; }
        
        $sql .= " GROUP BY DATE(ep.fecha_hora), t.id_trabajador 
                  ORDER BY DATE(ep.fecha_hora) DESC, t.nombre_completo ASC";

        $rows = Database::fetchAll($sql, $params);

        self::csvHeaders("asistencia_{$desde}_{$hasta}.csv");

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Fecha','Trabajador','DNI','Área','Cargo','Empresa',
            'Hora Ingreso','Salida Break','Ingreso Break','Salida Trabajo',
            'Horas Netas','Horas Programadas','Diferencia','Tipo',
        ], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_values($r), ';');
        }
        fclose($out);
        exit;
    }
}