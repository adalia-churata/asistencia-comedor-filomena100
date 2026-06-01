<?php
/**
 * models/EventoPersonal.php
 * Toda la lógica de negocio de marcaciones (Estructura Real de BD)
 */

class EventoPersonal
{
    // ── Tipos ──────────────────────────────────────────────────
    const TIPOS_LABORALES = ['INGRESO', 'SALIDA_BREAK', 'REGRESO_BREAK', 'SALIDA_TRABAJO'];
    const TIPOS_COMEDOR   = ['DESAYUNO', 'ALMUERZO', 'CENA'];

    /**
     * Detecta el tipo de comida según la hora actual
     */
    public static function detectarComida(string $hora = null): string
    {
        $h = $hora ?? date('H:i');
        if ($h >= '05:00' && $h <= '09:59') return 'DESAYUNO';
        if ($h >= '10:00' && $h <= '15:59') return 'ALMUERZO';
        return 'CENA';
    }

    /**
     * Determina con precisión matemática el siguiente tipo de evento laboral para un trabajador
     */
    public static function siguienteEventoLaboral(int $idTrabajador, string $fecha = null): string|null
    {
        $fecha = $fecha ?? date('Y-m-d');
        
        // ⚠️ CORRECCIÓN 1: Extraemos el historial completo del día ordenado por hora
        // Se cambió INGRESO_BREAK por REGRESO_BREAK para que coincida con tu base de datos
        $sql = "SELECT tipo_evento FROM eventos_personal
                WHERE id_trabajador = ? AND DATE(fecha_hora) = ?
                  AND tipo_evento IN ('INGRESO','SALIDA_BREAK','REGRESO_BREAK','SALIDA_TRABAJO')
                ORDER BY fecha_hora ASC";
                
        $marcaciones = Database::fetchAll($sql, [$idTrabajador, $fecha]);

        if (empty($marcaciones)) return 'INGRESO';

        // Mapeamos las marcaciones existentes en un array plano
        $historial = array_column($marcaciones, 'tipo_evento');

        // ── BLINDAJE DE SALIDA ANTICIPADA ──
        // Si el trabajador ya tiene guardado un 'REGRESO_BREAK' hoy,
        // el sistema sabe con certeza que el único paso pendiente es la SALIDA definitiva.
        if (in_array('REGRESO_BREAK', $historial)) {
            if (in_array('SALIDA_TRABAJO', $historial)) {
                return null; // Jornada ya cerrada
            }
            return 'SALIDA_TRABAJO'; // Desbloquea el escaneo anticipado (Ej: 14:54)
        }

        // Si no ha llegado al regreso de break, evaluamos el último paso de la secuencia
        $ultimoEvento = end($historial);

        return match($ultimoEvento) {
            'INGRESO'        => 'SALIDA_BREAK',
            'SALIDA_BREAK'   => 'REGRESO_BREAK',
            'REGRESO_BREAK'  => 'SALIDA_TRABAJO',
            'SALIDA_TRABAJO' => null,
            default          => null,
        };
    }

    /**
     * Registra un evento para un trabajador
     */
    public static function registrar(
        int    $idTrabajador,
        string $tipoEvento,
        string $observacion = ''
    ): array {
        $fechaHora = date('Y-m-d H:i:s');
        $fecha     = date('Y-m-d');

        // Verificar duplicado
        $existe = Database::fetchOne(
            "SELECT id_evento FROM eventos_personal
             WHERE id_trabajador = ? AND DATE(fecha_hora) = ? AND tipo_evento = ?",
            [$idTrabajador, $fecha, $tipoEvento]
        );
        if ($existe) {
            $label = self::labelTipo($tipoEvento);
            return ['ok' => false, 'error' => "Ya registró $label hoy."];
        }

        // Validar secuencia laboral e inyectar el Cooldown de 5 minutos
        if (in_array($tipoEvento, self::TIPOS_LABORALES)) {
            
            // ⚠️ CORRECCIÓN 2: Validamos la secuencia lógica de asistencia antes de insertar
            $esperado = self::siguienteEventoLaboral($idTrabajador, $fecha);
            if ($esperado !== $tipoEvento) {
                if ($esperado === null) {
                    return ['ok' => false, 'error' => 'Jornada laboral ya completada hoy.'];
                }
                return [
                    'ok'    => false,
                    'error' => 'Secuencia inválida. Se esperaba: ' . self::labelTipo($esperado),
                ];
            }

            // Buscamos si existe alguna marcación hace menos de 5 minutos usando funciones nativas de MySQL
            $duplicadoRapido = Database::fetchOne(
                "SELECT tipo_evento, TIME(fecha_hora) as hora_reg 
                 FROM eventos_personal
                 WHERE id_trabajador = ? 
                   AND DATE(fecha_hora) = ?
                   AND fecha_hora >= NOW() - INTERVAL 5 MINUTE
                 ORDER BY fecha_hora DESC LIMIT 1",
                [$idTrabajador, $fecha]
            );

            if ($duplicadoRapido) {
                $labelUltimo = self::labelTipo($duplicadoRapido['tipo_evento']);
                return [
                    'ok'    => false,
                    'error' => "⚠️ ¡Escaneo rápido! Ya registraste '{$labelUltimo}' hace menos de 5 minutos (a las {$duplicadoRapido['hora_reg']})."
                ];
            }
        }

        Database::query(
            "INSERT INTO eventos_personal (id_trabajador, fecha_hora, tipo_evento, observacion)
             VALUES (?, ?, ?, ?)",
            [$idTrabajador, $fechaHora, $tipoEvento, $observacion ?: null]
        );

        return [
            'ok'    => true,
            'tipo'  => $tipoEvento,
            'label' => self::labelTipo($tipoEvento),
            'hora'  => date('H:i:s'),
        ];
    }

    /**
     * Registra evento de comedor para un visitante (Apoya a tu tabla real consumo_visitantes)
     */
    public static function registrarVisitante(int $idVisitante, string $tipoEvento): array
    {
        $fecha = date('Y-m-d');

        // ⚠️ CORRECCIÓN 3: Apunta a tu tabla e índice real de consumos consumo_visitantes
        $existe = Database::fetchOne(
            "SELECT id_consumo FROM consumo_visitantes
             WHERE id_visitante = ? AND DATE(fecha_hora) = ? AND tipo_comida = ?",
            [$idVisitante, $fecha, $tipoEvento]
        );
        if ($existe) {
            return ['ok' => false, 'error' => "Visitante ya registró " . self::labelTipo($tipoEvento) . " hoy."];
        }

        Database::query(
            "INSERT INTO consumo_visitantes (id_visitante, fecha_hora, tipo_comida) VALUES (?, NOW(), ?)",
            [$idVisitante, $tipoEvento]
        );

        return ['ok' => true, 'tipo' => $tipoEvento, 'label' => self::labelTipo($tipoEvento), 'hora' => date('H:i:s')];
    }

    /**
     * Calcula horas trabajadas para una fecha y trabajador de forma dinámica desde eventos_personal
     */
    public static function calcularHoras(int $idTrabajador, string $fecha): array
    {
        // ⚠️ CORRECCIÓN 4: Eliminada la consulta a la tabla config_sistema e inyectada la constante global
        $horasProg = HORAS_PROGRAMADAS_DEFAULT;

        // Calculamos las marcas de tiempo agrupadas en vivo directo desde la tabla eventos_personal
        $row = Database::fetchOne(
            "SELECT 
                ROUND((
                    IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN tipo_evento = 'INGRESO' THEN fecha_hora END), MAX(CASE WHEN tipo_evento = 'SALIDA_TRABAJO' THEN fecha_hora END)), 0) - 
                    IFNULL(TIMESTAMPDIFF(MINUTE, MAX(CASE WHEN tipo_evento = 'SALIDA_BREAK' THEN fecha_hora END), MAX(CASE WHEN tipo_evento = 'REGRESO_BREAK' THEN fecha_hora END)), 0)
                ) / 60, 2) AS horas_netas
             FROM eventos_personal 
             WHERE id_trabajador = ? AND DATE(fecha_hora) = ?",
            [$idTrabajador, $fecha]
        );

        if (!$row || $row['horas_netas'] === null || $row['horas_netas'] == 0) {
            return [
                'horas_trabajadas' => null,
                'horas_programadas' => $horasProg,
                'diferencia' => null,
                'tipo_diferencia' => null,
            ];
        }

        $netasH     = (float)$row['horas_netas'];
        $diferencia = $netasH - $horasProg;

        return [
            'horas_trabajadas'  => $netasH,
            'horas_programadas' => $horasProg,
            'diferencia'        => abs(round($diferencia, 2)),
            'tipo_diferencia'   => $diferencia >= 0 ? 'extra' : 'deficitaria',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────
    public static function labelTipo(string $tipo): string
    {
        return match($tipo) {
            'INGRESO'        => 'Ingreso',
            'SALIDA_BREAK'   => 'Salida a break',
            'REGRESO_BREAK'  => 'Retorno de break',
            'SALIDA_TRABAJO' => 'Salida de trabajo',
            'DESAYUNO'       => 'Desayuno',
            'ALMUERZO'       => 'Almuerzo',
            'CENA'           => 'Cena',
            default          => $tipo,
        };
    }

    public static function iconoTipo(string $tipo): string
    {
        return match($tipo) {
            'INGRESO'        => '🟢',
            'SALIDA_BREAK'   => '🟡',
            'REGRESO_BREAK'  => '🔵',
            'SALIDA_TRABAJO' => '🔴',
            'DESAYUNO'       => '☕',
            'ALMUERZO'       => '🍽️',
            'CENA'           => '🌙',
            default          => '📌',
        };
    }
}
