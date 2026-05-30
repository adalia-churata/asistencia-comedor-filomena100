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

        // ── Trabajadores ──────────────────────────────────────
        if ($persona !== 'visitante') {
            $sql = "SELECT DATE(ep.fecha_hora) AS fecha, TIME(ep.fecha_hora) AS hora,
                           ep.tipo_evento, 'TRABAJADOR' AS tipo_persona,
                           t.nombre_completo AS nombre, t.dni,
                           a.nombre_area, t.cargo, t.empresa
                    FROM eventos_personal ep
                    JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
                    JOIN areas a ON a.id_area = t.id_area
                    WHERE ep.tipo_persona = 'TRABAJADOR'
                      AND ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                      AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
            $params = [$desde, $hasta];
            if ($area)   { $sql .= " AND t.id_area = ?";        $params[] = $area; }
            if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $params[] = $trabId; }
            if ($tipo)   { $sql .= " AND ep.tipo_evento = ?";   $params[] = $tipo; }
            $rows = array_merge($rows, Database::fetchAll($sql . " ORDER BY ep.fecha_hora DESC", $params));
        }

        // ── Visitantes ────────────────────────────────────────
        if ($persona !== 'trabajador') {
            $sql = "SELECT DATE(ep.fecha_hora) AS fecha, TIME(ep.fecha_hora) AS hora,
                           ep.tipo_evento, 'VISITANTE' AS tipo_persona,
                           v.nombre, v.dni,
                           'Visitante' AS nombre_area, '' AS cargo, v.empresa
                    FROM eventos_personal ep
                    JOIN visitantes v ON v.id_visitante = ep.id_visitante
                    WHERE ep.tipo_persona = 'VISITANTE'
                      AND ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                      AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
            $params = [$desde, $hasta];
            if ($tipo) { $sql .= " AND ep.tipo_evento = ?"; $params[] = $tipo; }
            $rows = array_merge($rows, Database::fetchAll($sql . " ORDER BY ep.fecha_hora DESC", $params));
        }

        // Ordenar por fecha desc
        usort($rows, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));

        self::csvHeaders("comedor_{$desde}_{$hasta}.csv");

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Fecha','Hora','Tipo Comida','Tipo Persona','Nombre','DNI','Área','Cargo','Empresa'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['fecha'], $r['hora'], $r['tipo_evento'], $r['tipo_persona'],
                $r['nombre'], $r['dni'] ?? '', $r['nombre_area'], $r['cargo'], $r['empresa'],
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
        $horasProg = (int)(Database::fetchOne(
            "SELECT valor FROM config_sistema WHERE clave='horas_programadas_dia'"
        )['valor'] ?? HORAS_PROGRAMADAS_DEFAULT);

        $sql = "SELECT v.fecha,
                       v.nombre_completo,
                       v.dni,
                       v.nombre_area,
                       v.cargo,
                       v.empresa,
                       TIME(v.hora_ingreso)        AS hora_ingreso,
                       TIME(v.hora_salida_break)   AS salida_break,
                       TIME(v.hora_ingreso_break)  AS ingreso_break,
                       TIME(v.hora_salida_trabajo) AS salida_trabajo,
                       ROUND(v.minutos_netos/60,2) AS horas_netas,
                       $horasProg                  AS horas_programadas,
                       ROUND((v.minutos_netos/60)-$horasProg,2) AS diferencia,
                       CASE WHEN (v.minutos_netos/60)>=$horasProg THEN 'Extra'
                            ELSE 'Deficitaria' END AS tipo_diferencia
                FROM v_asistencia_diaria v
                WHERE v.fecha BETWEEN ? AND ?";
        $params = [$desde, $hasta];
        if ($area)   { $sql .= " AND v.id_trabajador IN (SELECT id_trabajador FROM trabajadores WHERE id_area=?)"; $params[]=$area; }
        if ($trabId) { $sql .= " AND v.id_trabajador=?"; $params[]=$trabId; }
        $sql .= " ORDER BY v.fecha DESC, v.nombre_completo ASC";

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