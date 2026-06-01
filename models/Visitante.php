<?php
/**
 * models/Visitante.php
 * Toda la lógica de negocio del módulo de visitantes (Estructura Real de BD)
 */

class Visitante
{
    // ── Búsqueda autocomplete ──────────────────────────────────
    /**
     * Busca visitantes activos por nombre o empresa (Versión Ultra-Segura)
     */
    public static function buscar(string $q): array
    {
        $query = trim($q);
        if (strlen($query) < 2) return [];

        $like = '%' . $query . '%';
        
        // ⚠️ SOLUCIÓN: Usamos subconsultas directas para el conteo diario.
        // Esto elimina el GROUP BY problemático y garantiza que Laragon devuelva los registros al escribir.
        $sql = "SELECT
                   v.id_visitante,
                   v.nombre,
                   v.empresa,
                   '' AS dni,
                   1 AS activo,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'DESAYUNO') AS tuvo_desayuno,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'ALMUERZO') AS tuvo_almuerzo,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'CENA') AS tuvo_cena
                 FROM visitantes v
                 WHERE v.nombre LIKE ? OR v.empresa LIKE ?
                 ORDER BY v.nombre ASC
                 LIMIT 15";

        return Database::fetchAll($sql, [$like, $like]);
    }

    /**
     * Obtiene un visitante por id con estado de comedor hoy
     */
    public static function getPorId(int $id): array|false
    {
        // ⚠️ CORRECCIÓN: Estructura unificada mediante subconsultas seguras sin GROUP BY cruzado
        $sql = "SELECT
                   v.id_visitante, 
                   v.nombre, 
                   v.empresa, 
                   '' AS dni, 
                   1 AS activo,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'DESAYUNO') AS tuvo_desayuno,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'ALMUERZO') AS tuvo_almuerzo,
                   (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'CENA') AS tuvo_cena
                 FROM visitantes v
                 WHERE v.id_visitante = ?";

        return Database::fetchOne($sql, [$id]);
    }

    /**
     * Crea un nuevo visitante sin columnas fantasmas
     */
    public static function crear(string $nombre, string $empresa, ?string $dni = null): array 
    {
        $nombre  = trim($nombre);
        $empresa = trim($empresa);

        if (!$nombre)  return ['ok' => false, 'error' => 'El nombre es obligatorio'];
        if (!$empresa) return ['ok' => false, 'error' => 'La empresa es obligatoria'];

        $ok = Database::query(
            "INSERT INTO visitantes (nombre, empresa) VALUES (?, ?)",
            [$nombre, $empresa]
        );

        if (!$ok) return ['ok' => false, 'error' => 'No se pudo registrar en la base de datos'];

        return ['ok' => true, 'id' => (int) Database::lastInsertId()];
    }

    /**
     * Edita un visitante existente en base a tus columnas reales
     */
    public static function editar(int $id, array $datos): array
    {
        $fields = [];
        $params = [];

        foreach (['nombre', 'empresa'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $fields[] = "$campo = ?";
                $params[] = trim($datos[$campo]);
            }
        }

        if (!$fields) return ['ok' => true];

        $params[] = $id;
        Database::query(
            "UPDATE visitantes SET " . implode(', ', $fields) . " WHERE id_visitante = ?",
            $params
        );

        return ['ok' => true];
    }

    // ── Registro de eventos ────────────────────────────────────
    /**
     * Registra un consumo en la tabla consumo_visitantes
     */
    public static function registrarEvento(int $idVisitante, string $tipoEvento, string $origen = 'MANUAL', string $observacion = ''): array 
    {
        $tipoEvento = strtoupper(trim($tipoEvento));
        
        if ($tipoEvento === 'INGRESO' || $tipoEvento === 'SALIDA') {
            $tipoEvento = self::detectarComidaAlternativa();
        }

        $existe = Database::fetchOne(
            "SELECT id_consumo FROM consumo_visitantes
             WHERE id_visitante = ? AND tipo_comida = ? AND DATE(fecha_hora) = CURDATE()",
            [$idVisitante, $tipoEvento]
        );

        if ($existe) {
            return ['ok' => false, 'error' => "El visitante ya registró " . self::labelEvento($tipoEvento) . " hoy"];
        }

        Database::query(
            "INSERT INTO consumo_visitantes (id_visitante, fecha_hora, tipo_comida) VALUES (?, NOW(), ?)",
            [$idVisitante, $tipoEvento]
        );

        return [
            'ok'    => true,
            'tipo'  => $tipoEvento,
            'label' => self::labelEvento($tipoEvento),
            'hora'  => date('H:i:s'),
        ];
    }

    /**
     * Flujo completo: crear visitante + registrar evento
     */
    public static function crearYRegistrar(string $nombre, string $empresa, string $tipoEvento, ?string $dni = null, string $observacion = ''): array 
    {
        $result = self::crear($nombre, $empresa);
        if (!$result['ok']) return $result;

        $idVisitante = $result['id'];
        $evento = self::registrarEvento($idVisitante, $tipoEvento, 'MANUAL', $observacion);
        
        if (!$evento['ok']) return $evento;

        return [
            'ok'          => true,
            'id_visitante'=> $idVisitante,
            'nombre'      => $nombre,
            'empresa'     => $empresa,
            'evento'      => $evento['label'],
            'tipo_raw'    => $tipoEvento,
            'hora'        => $evento['hora'],
            'nuevo'       => true,
        ];
    }

    /**
     * Listado general con contadores en caliente
     */
    public static function listar(string $search = ''): array 
    {
        $sql = "SELECT 
                    v.id_visitante, 
                    v.nombre, 
                    v.empresa, 
                    '' AS dni, 
                    1 AS activo,
                    (SELECT COUNT(DISTINCT DATE(fecha_hora)) FROM consumo_visitantes WHERE id_visitante = v.id_visitante) AS dias_visitados,
                    (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'DESAYUNO') AS tuvo_desayuno,
                    (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'ALMUERZO') AS tuvo_almuerzo,
                    (SELECT COUNT(1) FROM consumo_visitantes WHERE id_visitante = v.id_visitante AND DATE(fecha_hora) = CURDATE() AND tipo_comida = 'CENA') AS tuvo_cena
                FROM visitantes v";
                
        $params = [];
        if (trim($search) !== '') {
            $sql .= " WHERE v.nombre LIKE ? OR v.empresa LIKE ?";
            $p = '%' . trim($search) . '%';
            $params[] = $p;
            $params[] = $p;
        }
        
        $sql .= " ORDER BY v.nombre ASC LIMIT 100";
        return Database::fetchAll($sql, $params);
    }

    /**
     * Obtiene el listado histórico de consumos para el modal de historial
     */
    public static function historialEvento(int $idVisitante): array
    {
        // ⚠️ CORRECCIÓN: Cierre sintáctico completo del método hacia tu tabla consumo_visitantes
        return Database::fetchAll(
            "SELECT fecha_hora, tipo_comida AS tipo_evento 
             FROM consumo_visitantes 
             WHERE id_visitante = ? 
             ORDER BY fecha_hora DESC LIMIT 200",
            [$idVisitante]
        );
    }

    // ── Helpers de Configuración ───────────────────────────────
    // ── Helpers de Configuración ───────────────────────────────
    public static function labelEvento(string $tipo): string
    {
        return match(strtoupper($tipo)) {
            'DESAYUNO' => 'Desayuno',
            'ALMUERZO' => 'Almuerzo',
            'CENA'     => 'Cena',
            default    => $tipo, // ⚠️ CORREGIDO: Cambiado ';' por ',' para sanar la sintaxis de PHP
        };
    }

    private static function detectarComidaAlternativa(): string
    {
        $h = date('H:i');
        if ($h >= '05:00' && $h <= '11:59') return 'DESAYUNO';
        if ($h >= '12:00' && $h <= '16:59') return 'ALMUERZO';
        return 'CENA';
    }
}