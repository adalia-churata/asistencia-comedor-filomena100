<?php
/**
 * models/Visitante.php
 * Toda la lógica de negocio del módulo de visitantes
 */

class Visitante
{
    // ── Búsqueda autocomplete ──────────────────────────────────
    /**
     * Busca visitantes por nombre, DNI o empresa
     * Devuelve máx 15 resultados con info de último evento hoy
     */
    public static function buscar(string $q): array
    {
        if (strlen(trim($q)) < 2) return [];

        $like = '%' . trim($q) . '%';
        return Database::fetchAll(
            "SELECT
               v.id_visitante,
               v.nombre,
               v.empresa,
               v.dni,
               v.activo,
               -- Eventos de comedor de hoy
               MAX(CASE WHEN ep.tipo_evento='DESAYUNO' AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_desayuno,
               MAX(CASE WHEN ep.tipo_evento='ALMUERZO' AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_almuerzo,
               MAX(CASE WHEN ep.tipo_evento='CENA'     AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_cena
             FROM visitantes v
             LEFT JOIN eventos_personal ep
               ON ep.id_visitante = v.id_visitante
              AND DATE(ep.fecha_hora) = CURDATE()
              AND ep.tipo_persona = 'VISITANTE'
             WHERE v.activo = 1
               AND (v.nombre LIKE ? OR v.dni LIKE ? OR v.empresa LIKE ?)
             GROUP BY v.id_visitante
             ORDER BY v.nombre
             LIMIT 15",
            [$like, $like, $like]
        );
    }

    /**
     * Obtiene un visitante por id con estado de comedor hoy
     */
    public static function getPorId(int $id): array|false
    {
        return Database::fetchOne(
            "SELECT
               v.*,
               MAX(CASE WHEN ep.tipo_evento='DESAYUNO' AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_desayuno,
               MAX(CASE WHEN ep.tipo_evento='ALMUERZO' AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_almuerzo,
               MAX(CASE WHEN ep.tipo_evento='CENA'     AND ep.tipo_persona='VISITANTE' THEN 1 END) AS tuvo_cena
             FROM visitantes v
             LEFT JOIN eventos_personal ep
               ON ep.id_visitante = v.id_visitante
              AND DATE(ep.fecha_hora) = CURDATE()
              AND ep.tipo_persona = 'VISITANTE'
             WHERE v.id_visitante = ? AND v.activo = 1
             GROUP BY v.id_visitante",
            [$id]
        );
    }

    /**
     * Obtiene visitante por DNI (para QR con DNI)
     */
    public static function getPorDni(string $dni): array|false
    {
        return Database::fetchOne(
            "SELECT * FROM visitantes WHERE dni = ? AND activo = 1 LIMIT 1",
            [$dni]
        );
    }

    /**
     * Crea un nuevo visitante
     * Retorna ['ok'=>true,'id'=>...] o ['ok'=>false,'error'=>...]
     */
    public static function crear(
        string $nombre,
        string $empresa,
        ?string $dni = null,
        ?string $observacion = null
    ): array {
        $nombre  = trim($nombre);
        $empresa = trim($empresa);
        $dni     = $dni ? trim($dni) : null;

        if (!$nombre)  return ['ok' => false, 'error' => 'El nombre es obligatorio'];
        if (!$empresa) return ['ok' => false, 'error' => 'La empresa es obligatoria'];

        // Verificar si ya existe por DNI (evitar duplicados)
        if ($dni) {
            $existe = Database::fetchOne(
                "SELECT id_visitante FROM visitantes WHERE dni = ? LIMIT 1",
                [$dni]
            );
            if ($existe) {
                return [
                    'ok'         => false,
                    'error'      => 'Ya existe un visitante con ese DNI',
                    'id_existente' => $existe['id_visitante'],
                ];
            }
        }

        Database::query(
            "INSERT INTO visitantes (nombre, empresa, dni, fecha_registro)
             VALUES (?, ?, ?, CURDATE())",
            [$nombre, $empresa, $dni]
        );

        return ['ok' => true, 'id' => (int) Database::lastInsertId()];
    }

    /**
     * Edita un visitante existente
     */
    public static function editar(int $id, array $datos): array
    {
        $fields = [];
        $params = [];

        foreach (['nombre', 'empresa', 'dni', 'activo'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $fields[] = "$campo = ?";
                $params[] = $datos[$campo] !== '' ? $datos[$campo] : null;
            }
        }

        if (!$fields) return ['ok' => false, 'error' => 'Sin datos para actualizar'];

        $params[] = $id;
        Database::query(
            "UPDATE visitantes SET " . implode(', ', $fields) . " WHERE id_visitante = ?",
            $params
        );

        return ['ok' => true];
    }

    // ── Registro de eventos ────────────────────────────────────
    /**
     * Registra un evento de comedor para un visitante
     * Inserta en eventos_personal (tabla unificada)
     */
    public static function registrarEvento(
        int    $idVisitante,
        string $tipoEvento,
        string $origen = 'MANUAL',
        string $observacion = ''
    ): array {
        $tiposPermitidos = ['DESAYUNO', 'ALMUERZO', 'CENA', 'INGRESO', 'SALIDA'];

        if (!in_array($tipoEvento, $tiposPermitidos)) {
            return ['ok' => false, 'error' => "Tipo de evento inválido: $tipoEvento"];
        }

        // Verificar duplicado hoy
        $existe = Database::fetchOne(
            "SELECT id_evento FROM eventos_personal
             WHERE id_visitante = ?
               AND tipo_persona = 'VISITANTE'
               AND tipo_evento = ?
               AND DATE(fecha_hora) = CURDATE()",
            [$idVisitante, $tipoEvento]
        );

        if ($existe) {
            $label = self::labelEvento($tipoEvento);
            return ['ok' => false, 'error' => "El visitante ya registró $label hoy"];
        }

        Database::query(
            "INSERT INTO eventos_personal
               (tipo_persona, id_visitante, fecha_hora, tipo_evento, origen, observacion)
             VALUES ('VISITANTE', ?, NOW(), ?, ?, ?)",
            [
                $idVisitante,
                $tipoEvento,
                $origen,
                $observacion ?: null,
            ]
        );

        return [
            'ok'    => true,
            'tipo'  => $tipoEvento,
            'label' => self::labelEvento($tipoEvento),
            'hora'  => date('H:i:s'),
        ];
    }

    /**
     * Flujo completo: crear visitante + registrar evento en una sola llamada
     */
    public static function crearYRegistrar(
        string $nombre,
        string $empresa,
        string $tipoEvento,
        ?string $dni = null,
        string $observacion = ''
    ): array {
        $result = self::crear($nombre, $empresa, $dni, $observacion);

        if (!$result['ok']) {
            // Si ya existe por DNI, usar ese visitante
            if (isset($result['id_existente'])) {
                $idVisitante = $result['id_existente'];
            } else {
                return $result;
            }
        } else {
            $idVisitante = $result['id'];
        }

        $evento = self::registrarEvento($idVisitante, $tipoEvento, 'MANUAL', $observacion);
        if (!$evento['ok']) return $evento;

        $vis = self::getPorId($idVisitante);

        return [
            'ok'          => true,
            'id_visitante'=> $idVisitante,
            'nombre'      => $vis['nombre']  ?? $nombre,
            'empresa'     => $vis['empresa'] ?? $empresa,
            'evento'      => $evento['label'],
            'tipo_raw'    => $tipoEvento,
            'hora'        => $evento['hora'],
            'nuevo'       => $result['ok'],
        ];
    }

    // ── Historial ──────────────────────────────────────────────
    /**
     * Listado de visitantes con filtros y paginación
     */
    public static function listar(
        string $q = '',
        int    $pagina = 1,
        int    $porPagina = 50
    ): array {
        $offset = ($pagina - 1) * $porPagina;
        $params = [];
        $where  = "WHERE v.activo = 1";

        if ($q = trim($q)) {
            $like = "%$q%";
            $where .= " AND (v.nombre LIKE ? OR v.dni LIKE ? OR v.empresa LIKE ?)";
            $params = [$like, $like, $like];
        }

        $sql = "SELECT
                  v.*,
                  COUNT(DISTINCT DATE(ep.fecha_hora)) AS dias_visitados,
                  MAX(ep.fecha_hora)                   AS ultima_visita
                FROM visitantes v
                LEFT JOIN eventos_personal ep
                  ON ep.id_visitante = v.id_visitante AND ep.tipo_persona = 'VISITANTE'
                $where
                GROUP BY v.id_visitante
                ORDER BY v.nombre
                LIMIT $porPagina OFFSET $offset";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Historial de eventos de un visitante específico
     */
    public static function historialEvento(int $idVisitante, int $limit = 30): array
    {
        return Database::fetchAll(
            "SELECT * FROM eventos_personal
             WHERE id_visitante = ? AND tipo_persona = 'VISITANTE'
             ORDER BY fecha_hora DESC LIMIT ?",
            [$idVisitante, $limit]
        );
    }

    // ── Helpers ───────────────────────────────────────────────
    public static function labelEvento(string $tipo): string
    {
        return match($tipo) {
            'DESAYUNO' => 'Desayuno',
            'ALMUERZO' => 'Almuerzo',
            'CENA'     => 'Cena',
            'INGRESO'  => 'Ingreso',
            'SALIDA'   => 'Salida',
            default    => $tipo,
        };
    }

    public static function emojiEvento(string $tipo): string
    {
        return match($tipo) {
            'DESAYUNO' => '☕',
            'ALMUERZO' => '🍽️',
            'CENA'     => '🌙',
            'INGRESO'  => '🟢',
            'SALIDA'   => '🔴',
            default    => '📌',
        };
    }
}