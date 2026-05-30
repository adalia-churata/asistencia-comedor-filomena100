<?php
/**
 * models/EventoPersonal.php
 * Toda la lógica de negocio de marcaciones
 */

class EventoPersonal
{
    // ── Tipos ──────────────────────────────────────────────────
    const TIPOS_LABORALES = ['INGRESO','SALIDA_BREAK','INGRESO_BREAK','SALIDA_TRABAJO'];
    const TIPOS_COMEDOR   = ['DESAYUNO','ALMUERZO','CENA'];

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
     * Determina el siguiente tipo de evento laboral para un trabajador
     */
    public static function siguienteEventoLaboral(int $idTrabajador, string $fecha = null): string|null
    {
        $fecha = $fecha ?? date('Y-m-d');
        $sql   = "SELECT tipo_evento FROM eventos_personal
                  WHERE id_trabajador = ? AND DATE(fecha_hora) = ?
                    AND tipo_evento IN ('INGRESO','SALIDA_BREAK','INGRESO_BREAK','SALIDA_TRABAJO')
                  ORDER BY fecha_hora DESC
                  LIMIT 1";
        $row = Database::fetchOne($sql, [$idTrabajador, $fecha]);

        if (!$row) return 'INGRESO';

        return match($row['tipo_evento']) {
            'INGRESO'        => 'SALIDA_BREAK',
            'SALIDA_BREAK'   => 'INGRESO_BREAK',
            'INGRESO_BREAK'  => 'SALIDA_TRABAJO',
            'SALIDA_TRABAJO' => null, // jornada completa
            default          => null,
        };
    }

    /**
     * Registra un evento para un trabajador
     * Retorna ['ok'=>true,'tipo'=>..., 'mensaje'=>...] o ['ok'=>false,'error'=>...]
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

        // Validar secuencia laboral
        if (in_array($tipoEvento, self::TIPOS_LABORALES)) {
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
     * Registra evento de comedor para un visitante
     */
    public static function registrarVisitante(int $idVisitante, string $tipoEvento): array
    {
        $fecha = date('Y-m-d');

        $existe = Database::fetchOne(
            "SELECT id_evento_vis FROM eventos_visitantes
             WHERE id_visitante = ? AND DATE(fecha_hora) = ? AND tipo_evento = ?",
            [$idVisitante, $fecha, $tipoEvento]
        );
        if ($existe) {
            return ['ok' => false, 'error' => "Visitante ya registró " . self::labelTipo($tipoEvento) . " hoy."];
        }

        Database::query(
            "INSERT INTO eventos_visitantes (id_visitante, fecha_hora, tipo_evento) VALUES (?, NOW(), ?)",
            [$idVisitante, $tipoEvento]
        );

        return ['ok' => true, 'tipo' => $tipoEvento, 'label' => self::labelTipo($tipoEvento), 'hora' => date('H:i:s')];
    }

    /**
     * Calcula horas trabajadas para una fecha y trabajador
     */
    public static function calcularHoras(int $idTrabajador, string $fecha): array
    {
        $horasProg = (int) (Database::fetchOne(
            "SELECT valor FROM config_sistema WHERE clave='horas_programadas_dia'"
        )['valor'] ?? HORAS_PROGRAMADAS_DEFAULT);

        $row = Database::fetchOne(
            "SELECT * FROM v_asistencia_diaria WHERE id_trabajador = ? AND fecha = ?",
            [$idTrabajador, $fecha]
        );

        if (!$row || $row['minutos_netos'] === null) {
            return [
                'horas_trabajadas' => null,
                'horas_programadas' => $horasProg,
                'diferencia' => null,
                'tipo_diferencia' => null,
            ];
        }

        $netasH    = round($row['minutos_netos'] / 60, 2);
        $diferencia = $netasH - $horasProg;

        return [
            'horas_trabajadas'  => $netasH,
            'horas_programadas' => $horasProg,
            'diferencia'        => abs(round($diferencia, 2)),
            'tipo_diferencia'   => $diferencia >= 0 ? 'extra' : 'deficitaria',
            'minutos_netos'     => $row['minutos_netos'],
            'minutos_brutos'    => $row['minutos_brutos'],
            'minutos_break'     => $row['minutos_break'],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────
    public static function labelTipo(string $tipo): string
    {
        return match($tipo) {
            'INGRESO'        => 'Ingreso',
            'SALIDA_BREAK'   => 'Salida a break',
            'INGRESO_BREAK'  => 'Retorno de break',
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
            'INGRESO_BREAK'  => '🔵',
            'SALIDA_TRABAJO' => '🔴',
            'DESAYUNO'       => '☕',
            'ALMUERZO'       => '🍽️',
            'CENA'           => '🌙',
            default          => '📌',
        ];
    }
}