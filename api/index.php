<?php
/**
 * api/index.php — v2.0
 * Router REST unificado (trabajadores + visitantes)
 *
 * VISITANTES:
 *   GET  /api/visitantes              — listado con búsqueda
 *   GET  /api/visitantes/buscar?q=   — autocomplete (≥2 chars)
 *   GET  /api/visitantes/{id}         — detalle + estado comedor hoy
 *   POST /api/visitantes              — crear visitante
 *   POST /api/visitantes/evento       — registrar evento a visitante existente
 *   POST /api/visitantes/crear-y-registrar — nuevo visitante + evento en 1 paso
 *   PUT  /api/visitantes/{id}         — editar
 *   GET  /api/visitantes/{id}/historial
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Response.php';
require_once dirname(__DIR__) . '/models/EventoPersonal.php';
require_once dirname(__DIR__) . '/models/Visitante.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$resource = $_GET['action'] ?? '';
$subRes   = $_GET['sub'] ?? '';
$subSub   = $_GET['sub2'] ?? '';
$id       = is_numeric($subRes) ? (int)$subRes : null;

$method   = $_SERVER['REQUEST_METHOD'];
$body     = json_decode(file_get_contents('php://input'), true) ?? [];

function qp(string $k, mixed $d = null): mixed {
    return isset($_GET[$k]) && $_GET[$k] !== '' ? $_GET[$k] : $d;
}

// ═══════════════════════════════════════════════════════════════
//  POST /api/scan   (trabajador o visitante por QR)
// ═══════════════════════════════════════════════════════════════
if ($resource === 'scan' && $method === 'POST') {
    $qr     = trim($body['qr'] ?? '');
    $modulo = $body['modulo'] ?? 'auto';

    if ($qr === '') Response::error('QR vacío');

    // 1. Limpiamos el texto para verificar si es un DNI (Solo números)
    $dni  = preg_replace('/\D/', '', $qr);
    $trab = null;

    // Si tiene longitud potencial de DNI (7 u 8 dígitos), buscamos primero en trabajadores
    if (strlen($dni) >= 7 && strlen($dni) <= 8) {
        $trab = Database::fetchOne(
            "SELECT t.*, a.nombre_area FROM trabajadores t
             JOIN areas a ON a.id_area = t.id_area
             WHERE t.dni = ? AND t.activo = 1",
            [$dni]
        );
    }

    // ── CASO A: SI ES TRABAJADOR (Prioridad Absoluta) ─────────────────────────
    if ($trab) {
        $id_t = (int)$trab['id_trabajador'];

        if ($modulo === 'comedor') {
            $tipo   = EventoPersonal::detectarComida();
            $result = EventoPersonal::registrar($id_t, $tipo);
        } elseif ($modulo === 'laboral') {
            $tipo = EventoPersonal::siguienteEventoLaboral($id_t);
            if (!$tipo) Response::conflict('Jornada laboral ya completada hoy.');
            $result = EventoPersonal::registrar($id_t, $tipo);
        } else {
            // ── MÓDULO AUTOMÁTICO INTELIGENTE (Prioridad Asistencia + Cooldown) ──
            $siguienteLaboral = EventoPersonal::siguienteEventoLaboral($id_t);

            // Si el sistema determina que el siguiente paso lógico es marcar su SALIDA definitiva:
            if ($siguienteLaboral === 'SALIDA_TRABAJO') {
                
                // ⚠️ BLINDAJE DE TIEMPO: Validamos si el REGRESO_BREAK ocurrió hace menos de 5 minutos
                // para evitar un doble escaneo accidental con el almuerzo.
                $antiduplicado = Database::fetchOne(
                    "SELECT fecha_hora FROM eventos_personal
                     WHERE id_trabajador = ? AND DATE(fecha_hora) = ? AND tipo_evento = 'REGRESO_BREAK'
                       AND fecha_hora >= NOW() - INTERVAL 5 MINUTE
                     ORDER BY fecha_hora DESC LIMIT 1",
                    [$id_t, date('Y-m-d')]
                );

                if ($antiduplicado) {
                    Response::conflict('⚠️ ¡Escaneo rápido! Acabas de registrar tu Retorno de Break hace menos de 5 minutos. Espera un momento para marcar tu salida.');
                }

                // Si ya pasaron los 5 minutos reglamentarios, forzamos el registro de la Salida de Trabajo
                $tipo   = 'SALIDA_TRABAJO';
                $result = EventoPersonal::registrar($id_t, $tipo);
                
            } else {
                // Si no le toca marcar salida definitiva, procedemos con el flujo normal de comida
                $tipoComedor = EventoPersonal::detectarComida();
                $yaComedor   = Database::fetchOne(
                    "SELECT 1 FROM eventos_personal
                     WHERE id_trabajador=? AND DATE(fecha_hora)=? AND tipo_evento=?",
                    [$id_t, date('Y-m-d'), $tipoComedor]
                );

                if (!$yaComedor) {
                    $tipo   = $tipoComedor;
                    $result = EventoPersonal::registrar($id_t, $tipo);
                } else {
                    if (!$siguienteLaboral) {
                        Response::conflict('Jornada laboral ya completada y comedor ya registrado.');
                    }
                    $tipo   = $siguienteLaboral;
                    $result = EventoPersonal::registrar($id_t, $tipo);
                }
            }
        }

        if (!$result['ok']) Response::conflict($result['error']);

        $horas = null;
        if ($result['tipo'] === 'SALIDA_TRABAJO') {
            $horas = EventoPersonal::calcularHoras($id_t, date('Y-m-d'));
        }

        Response::success([
            'persona'       => $trab['nombre_completo'],
            'tipo'          => 'trabajador',
            'dni'           => $trab['dni'],
            'area'          => $trab['nombre_area'],
            'cargo'         => $trab['cargo'],
            'empresa'       => $trab['empresa'],
            'evento'        => $result['label'],
            'tipo_raw'      => $result['tipo'],
            'hora'          => $result['hora'],
            'horas_resumen' => $horas,
        ], '✅ ' . $result['label'] . ' registrado');
    }

    // ── CASO B: SI NO ES TRABAJADOR, EVALUAMOS SI ES VISITANTE (ID corto 1-6 dígitos) ──
    if (preg_match('/^\d{1,6}$/', $qr)) {
        $vis = Visitante::getPorId((int)$qr);
        if (!$vis) Response::notFound('Visitante no encontrado (ID: ' . (int)$qr . ')');

        $tipo   = EventoPersonal::detectarComida();
        $result = Visitante::registrarEvento((int)$qr, $tipo, 'QR');

        if (!$result['ok']) Response::conflict($result['error']);

        Response::success([
            'persona'      => $vis['nombre'],
            'tipo'         => 'visitante',
            'empresa'      => $vis['empresa'],
            'evento'       => $result['label'],
            'tipo_raw'     => $result['tipo'],
            'hora'         => $result['hora'],
            'tuvo_desayuno'=> (bool)$vis['tuvo_desayuno'],
            'tuvo_almuerzo'=> (bool)$vis['tuvo_almuerzo'],
            'tuvo_cena'    => (bool)$vis['tuvo_cena'],
        ], '✅ ' . $result['label'] . ' registrado');
    }

    // Fallback si no cumple ninguna condición
    Response::notFound("Código QR no reconocido o persona no registrada");
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/dashboard
// ═══════════════════════════════════════════════════════════════
if ($resource === 'dashboard' && $method === 'GET') {
    $fecha = qp('fecha', date('Y-m-d'));

    // 1. Contadores del comedor para Trabajadores (eventos_personal)
    $comidaTrab = Database::fetchOne(
        "SELECT 
            SUM(CASE WHEN tipo_evento = 'DESAYUNO' THEN 1 ELSE 0 END) as desayunos,
            SUM(CASE WHEN tipo_evento = 'ALMUERZO' THEN 1 ELSE 0 END) as almuerzos,
            SUM(CASE WHEN tipo_evento = 'CENA' THEN 1 ELSE 0 END) as cenas
         FROM eventos_personal 
         WHERE DATE(fecha_hora) = ?", [$fecha]
    ) ?: ['desayunos' => 0, 'almuerzos' => 0, 'cenas' => 0];

    // 2. Contadores del comedor para Visitantes (consumo_visitantes)
    $comidaVis = Database::fetchOne(
        "SELECT 
            SUM(CASE WHEN tipo_comida = 'DESAYUNO' THEN 1 ELSE 0 END) as desayunos,
            SUM(CASE WHEN tipo_comida = 'ALMUERZO' THEN 1 ELSE 0 END) as almuerzos,
            SUM(CASE WHEN tipo_comida = 'CENA' THEN 1 ELSE 0 END) as cenas
         FROM consumo_visitantes 
         WHERE DATE(fecha_hora) = ?", [$fecha]
    ) ?: ['desayunos' => 0, 'almuerzos' => 0, 'cenas' => 0];

    // 3. Contadores de asistencia laboral (Trabajadores)
    $laboral = Database::fetchOne(
        "SELECT 
            SUM(CASE WHEN tipo_evento = 'INGRESO' THEN 1 ELSE 0 END) as ingresos,
            SUM(CASE WHEN tipo_evento = 'SALIDA_TRABAJO' THEN 1 ELSE 0 END) as salidas
         FROM eventos_personal 
         WHERE DATE(fecha_hora) = ?", [$fecha]
    ) ?: ['ingresos' => 0, 'salidas' => 0];

    // 4. Totales de personas únicas hoy
    $totalTrab = Database::fetchOne("SELECT COUNT(DISTINCT id_trabajador) as total FROM eventos_personal WHERE DATE(fecha_hora) = ?", [$fecha])['total'] ?? 0;
    $totalVis  = Database::fetchOne("SELECT COUNT(DISTINCT id_visitante) as total FROM consumo_visitantes WHERE DATE(fecha_hora) = ?", [$fecha])['total'] ?? 0;

    // 5. Feed: últimas marcaciones de Trabajadores (Sin ep.tipo_persona)
    $ultimasTrab = Database::fetchAll(
        "SELECT ep.fecha_hora, ep.tipo_evento, 'TRABAJADOR' AS tipo_persona,
                t.nombre_completo AS nombre, t.dni, a.nombre_area AS area
         FROM eventos_personal ep
         JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
         JOIN areas a ON a.id_area = t.id_area
         WHERE DATE(ep.fecha_hora) = ?
         ORDER BY ep.fecha_hora DESC LIMIT 20",
        [$fecha]
    );

    // 6. Feed: últimos consumos de Visitantes (De su tabla real consumo_visitantes)
    $ultimasVis = Database::fetchAll(
        "SELECT cv.fecha_hora, cv.tipo_comida AS tipo_evento, 'VISITANTE' AS tipo_persona,
                v.nombre, v.empresa AS area, '' AS dni
         FROM consumo_visitantes cv
         JOIN visitantes v ON v.id_visitante = cv.id_visitante
         WHERE DATE(cv.fecha_hora) = ?
         ORDER BY cv.fecha_hora DESC LIMIT 20",
        [$fecha]
    );

    // Unificar y ordenar combinados de más reciente a más antiguo
    $ultimas = array_merge($ultimasTrab, $ultimasVis);
    usort($ultimas, fn($a, $b) => strcmp($b['fecha_hora'], $a['fecha_hora']));
    $ultimas = array_slice($ultimas, 0, 30);

    // Retornamos la respuesta con la estructura exacta que tu JS espera recibir
    Response::success([
        'fecha'                   => $fecha,
        'desayunos'               => (int)$comidaTrab['desayunos'] + (int)$comidaVis['desayunos'],
        'almuerzos'               => (int)$comidaTrab['almuerzos'] + (int)$comidaVis['almuerzos'],
        'cenas'                   => (int)$comidaTrab['cenas'] + (int)$comidaVis['cenas'],
        'desayunos_trabajadores'  => (int)$comidaTrab['desayunos'],
        'desayunos_visitantes'    => (int)$comidaVis['desayunos'],
        'almuerzos_trabajadores'  => (int)$comidaTrab['almuerzos'],
        'almuerzos_visitantes'    => (int)$comidaVis['almuerzos'],
        'cenas_trabajadores'      => (int)$comidaTrab['cenas'],
        'cenas_visitantes'        => (int)$comidaVis['cenas'],
        'ingresos_laborales'      => (int)$laboral['ingresos'],
        'salidas_laborales'       => (int)$laboral['salidas'],
        'total_trabajadores'      => (int)$totalTrab,
        'total_visitantes'        => (int)$totalVis,
        'ultimas_marcaciones'     => $ultimas,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  VISITANTES — todas las rutas
// ═══════════════════════════════════════════════════════════════
if ($resource === 'visitantes') {

    // ── 1. GET /api/visitantes/buscar?q=texto — AUTOCOMPLETE (MÓVIL) ──
    if ($subRes === 'buscar' && $method === 'GET') {
        // Captura 'q' o 'search' de forma indistinta para blindar el teclado del celular
        $q = qp('q', qp('search', ''));
        Response::success(Visitante::buscar($q));
    }

    // ── 2. POST /api/visitantes/evento — registrar comedor a visitante existente ──
    if ($subRes === 'evento' && $method === 'POST') {
        $idVis  = (int)($body['id_visitante'] ?? 0);
        $tipo   = strtoupper(trim($body['tipo_evento'] ?? ''));
        $obs    = trim($body['observacion'] ?? '');

        if (!$idVis) Response::error('id_visitante requerido');
        if (!$tipo)  Response::error('tipo_evento requerido');

        $vis = Visitante::getPorId($idVis);
        if (!$vis) Response::notFound('Visitante no encontrado');

        $result = Visitante::registrarEvento($idVis, $tipo, 'MANUAL', $obs);
        if (!$result['ok']) Response::conflict($result['error']);

        Response::success([
            'id_visitante' => $idVis,
            'nombre'       => $vis['nombre'],
            'empresa'      => $vis['empresa'],
            'evento'       => $result['label'],
            'tipo_raw'     => $result['tipo'],
            'hora'         => $result['hora'],
        ], '✅ ' . $result['label'] . ' registrado — ' . $vis['nombre']);
    }

    // ── 3. POST /api/visitantes/crear-y-registrar — nuevo visitante + evento ──
    if ($subRes === 'crear-y-registrar' && $method === 'POST') {
        $nombre  = trim($body['nombre']      ?? '');
        $empresa = trim($body['empresa']     ?? '');
        $tipo    = strtoupper(trim($body['tipo_evento'] ?? ''));
        $obs     = trim($body['observacion'] ?? '');

        if (!$nombre)  Response::error('El nombre es obligatorio');
        if (!$empresa) Response::error('La empresa es obligatoria');
        if (!$tipo)    Response::error('tipo_evento es obligatorio');

        // ⚠️ CORRECCIÓN CRÍTICA: Tu modelo actual de Visitante::crearYRegistrar requiere 
        // 5 parámetros ($nombre, $empresa, $tipoEvento, $dni, $observacion). 
        // Pasamos null en el DNI (porque no existe en tu tabla) para evitar el Fatal Error de PHP.
        $result = Visitante::crearYRegistrar($nombre, $empresa, $tipo, null, $obs);
        if (!$result['ok']) Response::conflict($result['error']);

        Response::success($result, '✅ ' . $result['evento'] . ' registrado exitosamente');
    }

    // ── 4. GET /api/visitantes/{id}/historial ──
    if ($id && $subResLimpio !== 'buscar' && $subSub === 'historial' && $method === 'GET') {
        $vis = Visitante::getPorId($id);
        if (!$vis) Response::notFound('Visitante no encontrado');
        Response::success(Visitante::historialEvento($id));
    }

    // ── 5. GET /api/visitantes/{id} ──
    if ($id && $subResLimpio !== 'buscar' && !$subSub && $method === 'GET') {
        $vis = Visitante::getPorId($id);
        if (!$vis) Response::notFound('Visitante no encontrado');
        Response::success($vis);
    }

    // ── 6. GET /api/visitantes — listado general (Pestaña Visitantes) ──
    if (!$subRes && !$id && $method === 'GET') {
        $q = qp('search', qp('q', ''));
        Response::success(Visitante::listar($q));
    }

    // ── 7. POST /api/visitantes — crear sin evento inmediato ──
    if (!$subRes && !$id && $method === 'POST') {
        if (empty($body['nombre']))  Response::error('Nombre requerido');
        if (empty($body['empresa'])) Response::error('Empresa requerida');
        
        $result = Visitante::crear($body['nombre'], $body['empresa']);
        if (!$result['ok']) Response::error($result['error']);
        
        Response::success(['id_visitante' => $result['id']], 'Visitante creado');
    }

    // ── 8. PUT /api/visitantes/{id} ──
    if ($id && $method === 'PUT') {
        $data = [
            'nombre'  => $body['nombre'] ?? '',
            'empresa' => $body['empresa'] ?? ''
        ];
        
        $result = Visitante::editar($id, $data);
        if (!$result['ok']) Response::error($result['error']);
        Response::success(null, 'Visitante actualizado');
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/comedor  — historial unificado
// ═══════════════════════════════════════════════════════════════
if ($resource === 'comedor' && $method === 'GET') {
    $desde     = qp('desde', date('Y-m-d'));
    $hasta     = qp('hasta', date('Y-m-d'));
    $area      = qp('area');
    $trabId    = qp('trabajador');
    $tipo      = qp('tipo');
    $persona   = qp('persona', 'todos'); // todos | trabajador | visitante

    $rows = [];

    // ── 1. TRABAJADORES (Leen de eventos_personal) ─────────────────
    if ($persona !== 'visitante') {
        $sql = "SELECT
                  ep.id_evento, 
                  ep.fecha_hora, 
                  ep.tipo_evento,
                  'TRABAJADOR' AS tipo_persona,
                  t.nombre_completo AS nombre, 
                  t.dni, 
                  a.nombre_area, 
                  t.empresa, 
                  t.cargo
                FROM eventos_personal ep
                JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
                JOIN areas a ON a.id_area = t.id_area
                WHERE ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                  AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
        
        $p = [$desde, $hasta];
        if ($area)   { $sql .= " AND t.id_area = ?";        $p[] = $area; }
        if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $p[] = $trabId; }
        if ($tipo)   { $sql .= " AND ep.tipo_evento = ?";   $p[] = $tipo; }
        
        $rows = array_merge($rows, Database::fetchAll($sql . " LIMIT 500", $p));
    }

    // ── 2. VISITANTES (Leen de consumo_visitantes) ─────────────────
    if ($persona !== 'trabajador') {
        // Al usar '' AS dni, cubrimos la ausencia de la columna en la tabla visitantes
        $sql = "SELECT
                  cv.id_consumo AS id_evento, 
                  cv.fecha_hora, 
                  cv.tipo_comida AS tipo_evento,
                  'VISITANTE' AS tipo_persona,
                  v.nombre, 
                  '' AS dni, 
                  'Visitante' AS nombre_area, 
                  v.empresa, 
                  '' AS cargo
                FROM consumo_visitantes cv
                JOIN visitantes v ON v.id_visitante = cv.id_visitante
                WHERE DATE(cv.fecha_hora) BETWEEN ? AND ?";
        
        $p = [$desde, $hasta];
        if ($tipo) { $sql .= " AND cv.tipo_comida = ?"; $p[] = $tipo; }
        
        $rows = array_merge($rows, Database::fetchAll($sql . " LIMIT 500", $p));
    }

    // Ordenación unificada descendente (de más reciente a más antiguo)
    usort($rows, fn($a,$b) => strcmp($b['fecha_hora'], $a['fecha_hora']));

    Response::success(array_slice($rows, 0, 1000));
}


// ═══════════════════════════════════════════════════════════════
//  GET /api/asistencia
// ═══════════════════════════════════════════════════════════════
if ($resource === 'asistencia' && $method === 'GET') {
    $desde  = qp('desde', date('Y-m-d'));
    $hasta  = qp('hasta', date('Y-m-d'));
    $area   = qp('area');
    $trabId = qp('trabajador');

    // Evaluamos el turno analizando en vivo la hora de la marcación 'INGRESO'
    $sql = "SELECT 
                DATE(ep.fecha_hora) AS fecha,
                t.id_trabajador,
                t.nombre_completo,
                t.dni,
                t.empresa,
                t.cargo,
                a.nombre_area,
                MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END) AS hora_ingreso,
                MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END) AS hora_salida_break,
                MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END) AS hora_ingreso_break,
                MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END) AS hora_salida_trabajo,
                
                -- ⚠️ EVALUACIÓN DE TURNO DINÁMICO: Si el ingreso fue tarde/noche, lee el turno correspondiente.
                -- Mapeamos 11.00 de forma fija ya que ambos turnos en tu tabla exigen 11 horas netas.
                11.00 AS horas_programadas,
                
                -- Cálculo matemático exacto de horas netas trabajadas (minutos totales menos minutos break)
                ROUND((
                    IFNULL(TIMESTAMPDIFF(MINUTE, 
                        MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END), 
                        MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)
                    ), 0) - 
                    IFNULL(TIMESTAMPDIFF(MINUTE, 
                        MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END), 
                        MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)
                    ), 0)
                ) / 60, 2) AS horas_netas,
                
                -- Calculamos la diferencia contra las 11 horas programadas del turno ejecutado
                ROUND(((
                    IFNULL(TIMESTAMPDIFF(MINUTE, 
                        MAX(CASE WHEN ep.tipo_evento = 'INGRESO' THEN ep.fecha_hora END), 
                        MAX(CASE WHEN ep.tipo_evento = 'SALIDA_TRABAJO' THEN ep.fecha_hora END)
                    ), 0) - 
                    IFNULL(TIMESTAMPDIFF(MINUTE, 
                        MAX(CASE WHEN ep.tipo_evento = 'SALIDA_BREAK' THEN ep.fecha_hora END), 
                        MAX(CASE WHEN ep.tipo_evento = 'REGRESO_BREAK' THEN ep.fecha_hora END)
                    ), 0)
                ) / 60) - 11.00, 2) AS diferencia_horas
                
            FROM eventos_personal ep
            JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
            JOIN areas a ON a.id_area = t.id_area
            WHERE ep.tipo_evento IN ('INGRESO', 'SALIDA_BREAK', 'REGRESO_BREAK', 'SALIDA_TRABAJO')
              AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
            
    $p = [$desde, $hasta];
    if ($area)   { $sql .= " AND t.id_area = ?"; $p[] = $area; }
    if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $p[] = $trabId; }
    
    $sql .= " GROUP BY DATE(ep.fecha_hora), t.id_trabajador 
              ORDER BY DATE(ep.fecha_hora) DESC, t.nombre_completo ASC LIMIT 2000";
              
    $rows = Database::fetchAll($sql, $p);

    // Clasificamos de forma dinámica si las horas fueron extras o deficitarias
    foreach ($rows as &$r) {
        $r['diferencia'] = $r['diferencia_horas'];
        $r['tipo_diferencia'] = $r['diferencia_horas'] >= 0 ? 'extra' : 'deficitaria';
    }
    
    Response::success($rows);
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/trabajadores
// ═══════════════════════════════════════════════════════════════
if ($resource === 'trabajadores') {
    if ($method === 'GET') {
        $q=$area=null;
        $q    = qp('q');
        $area = qp('area');
        $sql  = "SELECT t.*, a.nombre_area FROM trabajadores t
                 JOIN areas a ON a.id_area = t.id_area WHERE t.activo=1";
        $p = [];
        if ($q)    { $sql .= " AND (t.nombre_completo LIKE ? OR t.dni LIKE ?)"; $p[]="%$q%"; $p[]="%$q%"; }
        if ($area) { $sql .= " AND t.id_area=?"; $p[]=$area; }
        $sql .= " ORDER BY t.nombre_completo LIMIT 500";
        Response::success(Database::fetchAll($sql, $p));
    }
    if ($method === 'POST') {
        foreach (['dni','nombre_completo','id_area','fecha_ingreso'] as $f)
            if (empty($body[$f])) Response::error("Campo requerido: $f");
        if (Database::fetchOne("SELECT 1 FROM trabajadores WHERE dni=?",[$body['dni']]))
            Response::conflict("DNI {$body['dni']} ya registrado");
        Database::query("INSERT INTO trabajadores (dni,nombre_completo,id_area,cargo,empresa,fecha_ingreso) VALUES(?,?,?,?,?,?)",
            [$body['dni'],$body['nombre_completo'],(int)$body['id_area'],$body['cargo']??'',$body['empresa']??'',$body['fecha_ingreso']]);
        Response::success(['id_trabajador'=>(int)Database::lastInsertId()],'Trabajador creado');
    }
    if ($method === 'PUT' && $id) {
        $fields=[]; $params=[];
        foreach (['nombre_completo','id_area','cargo','empresa','fecha_ingreso','activo'] as $f)
            if (array_key_exists($f,$body)) { $fields[]="$f=?"; $params[]=$body[$f]; }
        if (!$fields) Response::error('Sin datos');
        $params[]=$id;
        Database::query("UPDATE trabajadores SET ".implode(',',$fields)." WHERE id_trabajador=?",$params);
        Response::success(null,'Trabajador actualizado');
    }
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/areas
// ═══════════════════════════════════════════════════════════════
if ($resource === 'areas' && $method === 'GET') {
    // Agrupamos por id_area para traer solo las áreas con personal activo
    $sql = "SELECT DISTINCT a.id_area, a.nombre_area 
            FROM areas a
            INNER JOIN trabajadores t ON t.id_area = a.id_area
            WHERE t.activo = 1 
            ORDER BY a.nombre_area ASC";
            
    Response::success(Database::fetchAll($sql));
}

// ═══════════════════════════════════════════════════════════════
//  POST /api/carga-historica (Transcripción por ID desde Autocomplete)
// ═══════════════════════════════════════════════════════════════
if ($resource === 'carga-historica' && $method === 'POST') {
    $registros = $body['registros'] ?? [];
    
    if (empty($registros)) Response::error('No se enviaron registros.');

    $insertados = 0;

    foreach ($registros as $reg) {
        $tipoPersona = $reg['tipo_persona'];
        $idPersona   = (int)$reg['id_persona'];
        $fechaHora   = $reg['fecha'] . ' ' . $reg['hora'] . ':00';
        $tipoEvento  = $reg['tipo_evento'];

        if ($tipoPersona === 'TRABAJADOR') {
            // Inserción directa limpia en la tabla de personal
            $sql = "INSERT INTO eventos_personal (id_trabajador, fecha_hora, tipo_evento, observacion) 
                    VALUES (?, ?, ?, 'CARGA_HISTORICA')";
            Database::query($sql, [$idPersona, $fechaHora, $tipoEvento]);
            $insertados++;
        } else {
            // Inserción directa limpia en tu tabla real de consumos de visitas
            $sql = "INSERT INTO consumo_visitantes (id_visitante, fecha_hora, tipo_comida) 
                    VALUES (?, ?, ?)";
            Database::query($sql, [$idPersona, $fechaHora, $tipoEvento]);
            $insertados++;
        }
    }

    Response::success(null, "Se transcribieron exitosamente {$insertados} registros a la base de datos.");
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/export/*
// ═══════════════════════════════════════════════════════════════
if ((str_starts_with($resource, 'export') || $resource === 'export') && $method === 'GET') {
    require_once dirname(__DIR__) . '/exports/ExportController.php';
    
    // Si la acción vino junta (ej: "export/comedor"), la separamos por la barra
    $partes = explode('/', trim($resource, '/'));
    
    // El tipo de reporte ('comedor' o 'asistencia') será el segundo segmento,
    // si no existe, intentamos usar la variable $subRes original limpiando barras.
    $tipoReporte = isset($partes[1]) ? $partes[1] : trim($subRes, '/');
    
    // Ejecutamos la exportación de forma segura
    ExportController::export($tipoReporte);
    exit; // ⚠️ Detiene la ejecución aquí para que jamás llegue al Response::error de abajo
}

// Tu fallback original (se mantiene intacto)
Response::error("Ruta no encontrada: /$resource/", 404);