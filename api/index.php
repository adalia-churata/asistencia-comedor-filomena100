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

$uri      = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts    = explode('/', $uri);
$base     = BASE_URL ? trim(BASE_URL, '/') : '';
if ($base) $parts = array_slice($parts, substr_count($base, '/') + 1);

$resource = $parts[1] ?? '';           // 'scan' | 'dashboard' | 'visitantes' ...
$subRes   = $parts[2] ?? '';           // id numérico O sub-ruta ('buscar','evento',...)
$subSub   = $parts[3] ?? '';           // 'historial'
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

    // ── Visitante: QR contiene id_visitante (1-7 dígitos) ──
    if (preg_match('/^\d{1,7}$/', $qr)) {
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

    // ── Trabajador: QR contiene DNI ──
    $dni  = preg_replace('/\D/', '', $qr);
    $trab = Database::fetchOne(
        "SELECT t.*, a.nombre_area FROM trabajadores t
         JOIN areas a ON a.id_area = t.id_area
         WHERE t.dni = ? AND t.activo = 1",
        [$dni]
    );
    if (!$trab) Response::notFound("Persona no encontrada (QR: $qr)");

    $id_t = (int)$trab['id_trabajador'];

    if ($modulo === 'comedor') {
        $tipo   = EventoPersonal::detectarComida();
        $result = EventoPersonal::registrar($id_t, $tipo);
    } elseif ($modulo === 'laboral') {
        $tipo = EventoPersonal::siguienteEventoLaboral($id_t);
        if (!$tipo) Response::conflict('Jornada laboral ya completada hoy.');
        $result = EventoPersonal::registrar($id_t, $tipo);
    } else {
        $tipoComedor = EventoPersonal::detectarComida();
        $yaComedor   = Database::fetchOne(
            "SELECT 1 FROM eventos_personal
             WHERE id_trabajador=? AND tipo_persona='TRABAJADOR'
               AND DATE(fecha_hora)=? AND tipo_evento=?",
            [$id_t, date('Y-m-d'), $tipoComedor]
        );
        if (!$yaComedor) {
            $tipo   = $tipoComedor;
            $result = EventoPersonal::registrar($id_t, $tipo);
        } else {
            $tipo = EventoPersonal::siguienteEventoLaboral($id_t);
            if (!$tipo) Response::conflict('Jornada laboral ya completada y comedor ya registrado.');
            $result = EventoPersonal::registrar($id_t, $tipo);
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

// ═══════════════════════════════════════════════════════════════
//  GET /api/dashboard
// ═══════════════════════════════════════════════════════════════
if ($resource === 'dashboard' && $method === 'GET') {
    $fecha = qp('fecha', date('Y-m-d'));

    $resumen = Database::fetchOne("SELECT * FROM v_resumen_dia WHERE fecha = ?", [$fecha])
        ?: ['desayunos'=>0,'almuerzos'=>0,'cenas'=>0,
            'desayunos_trabajadores'=>0,'desayunos_visitantes'=>0,
            'almuerzos_trabajadores'=>0,'almuerzos_visitantes'=>0,
            'cenas_trabajadores'=>0,'cenas_visitantes'=>0,
            'ingresos_laborales'=>0,'salidas_laborales'=>0,
            'total_trabajadores'=>0,'total_visitantes'=>0];

    // Feed unificado: trabajadores + visitantes
    $ultimasTrab = Database::fetchAll(
        "SELECT ep.fecha_hora, ep.tipo_evento, ep.tipo_persona,
                t.nombre_completo AS nombre, t.dni, a.nombre_area AS area
         FROM eventos_personal ep
         JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
         JOIN areas a ON a.id_area = t.id_area
         WHERE DATE(ep.fecha_hora) = ? AND ep.tipo_persona = 'TRABAJADOR'
         ORDER BY ep.fecha_hora DESC LIMIT 20",
        [$fecha]
    );
    $ultimasVis = Database::fetchAll(
        "SELECT ep.fecha_hora, ep.tipo_evento, ep.tipo_persona,
                v.nombre, v.empresa AS area, '' AS dni
         FROM eventos_personal ep
         JOIN visitantes v ON v.id_visitante = ep.id_visitante
         WHERE DATE(ep.fecha_hora) = ? AND ep.tipo_persona = 'VISITANTE'
         ORDER BY ep.fecha_hora DESC LIMIT 20",
        [$fecha]
    );

    $ultimas = array_merge($ultimasTrab, $ultimasVis);
    usort($ultimas, fn($a, $b) => strcmp($b['fecha_hora'], $a['fecha_hora']));
    $ultimas = array_slice($ultimas, 0, 30);

    Response::success([
        'fecha'                   => $fecha,
        'desayunos'               => (int)$resumen['desayunos'],
        'almuerzos'               => (int)$resumen['almuerzos'],
        'cenas'                   => (int)$resumen['cenas'],
        'desayunos_trabajadores'  => (int)$resumen['desayunos_trabajadores'],
        'desayunos_visitantes'    => (int)$resumen['desayunos_visitantes'],
        'almuerzos_trabajadores'  => (int)$resumen['almuerzos_trabajadores'],
        'almuerzos_visitantes'    => (int)$resumen['almuerzos_visitantes'],
        'cenas_trabajadores'      => (int)$resumen['cenas_trabajadores'],
        'cenas_visitantes'        => (int)$resumen['cenas_visitantes'],
        'ingresos_laborales'      => (int)$resumen['ingresos_laborales'],
        'salidas_laborales'       => (int)$resumen['salidas_laborales'],
        'total_trabajadores'      => (int)$resumen['total_trabajadores'],
        'total_visitantes'        => (int)$resumen['total_visitantes'],
        'ultimas_marcaciones'     => $ultimas,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  VISITANTES — todas las rutas
// ═══════════════════════════════════════════════════════════════
if ($resource === 'visitantes') {

    // GET /api/visitantes/buscar?q=texto  — autocomplete
    if ($subRes === 'buscar' && $method === 'GET') {
        $q = qp('q', '');
        Response::success(Visitante::buscar($q));
    }

    // POST /api/visitantes/evento  — registrar comedor a visitante existente
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

    // POST /api/visitantes/crear-y-registrar — nuevo visitante + evento
    if ($subRes === 'crear-y-registrar' && $method === 'POST') {
        $nombre  = trim($body['nombre']      ?? '');
        $empresa = trim($body['empresa']     ?? '');
        $tipo    = strtoupper(trim($body['tipo_evento'] ?? ''));
        $dni     = trim($body['dni']         ?? '') ?: null;
        $obs     = trim($body['observacion'] ?? '');

        if (!$nombre)  Response::error('El nombre es obligatorio');
        if (!$empresa) Response::error('La empresa es obligatoria');
        if (!$tipo)    Response::error('tipo_evento es obligatorio');

        $result = Visitante::crearYRegistrar($nombre, $empresa, $tipo, $dni, $obs);
        if (!$result['ok']) Response::conflict($result['error']);

        Response::success($result,
            ($result['nuevo'] ? '✅ Visitante creado — ' : '✅ Visitante existente — ')
            . $result['evento'] . ' registrado'
        );
    }

    // GET /api/visitantes/{id}/historial
    if ($id && $subSub === 'historial' && $method === 'GET') {
        $vis = Visitante::getPorId($id);
        if (!$vis) Response::notFound('Visitante no encontrado');
        Response::success([
            'visitante' => $vis,
            'eventos'   => Visitante::historialEvento($id),
        ]);
    }

    // GET /api/visitantes/{id}
    if ($id && !$subSub && $method === 'GET') {
        $vis = Visitante::getPorId($id);
        if (!$vis) Response::notFound('Visitante no encontrado');
        Response::success($vis);
    }

    // GET /api/visitantes  — listado
    if (!$subRes && $method === 'GET') {
        $q = qp('q', '');
        Response::success(Visitante::listar($q));
    }

    // POST /api/visitantes  — crear sin evento
    if (!$subRes && $method === 'POST') {
        if (empty($body['nombre']))  Response::error('Nombre requerido');
        if (empty($body['empresa'])) Response::error('Empresa requerida');
        $result = Visitante::crear($body['nombre'], $body['empresa'], $body['dni'] ?? null);
        if (!$result['ok']) {
            if (isset($result['id_existente']))
                Response::conflict($result['error'] . ' (ID: ' . $result['id_existente'] . ')');
            Response::error($result['error']);
        }
        Response::success(['id_visitante' => $result['id']], 'Visitante creado');
    }

    // PUT /api/visitantes/{id}
    if ($id && $method === 'PUT') {
        $result = Visitante::editar($id, $body);
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

    // Trabajadores
    if ($persona !== 'visitante') {
        $sql = "SELECT
                  ep.id_evento, ep.fecha_hora, ep.tipo_evento,
                  'TRABAJADOR' AS tipo_persona,
                  t.nombre_completo AS nombre, t.dni, a.nombre_area, t.empresa, t.cargo
                FROM eventos_personal ep
                JOIN trabajadores t ON t.id_trabajador = ep.id_trabajador
                JOIN areas a ON a.id_area = t.id_area
                WHERE ep.tipo_persona = 'TRABAJADOR'
                  AND ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                  AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
        $p = [$desde, $hasta];
        if ($area)   { $sql .= " AND t.id_area = ?";        $p[] = $area; }
        if ($trabId) { $sql .= " AND ep.id_trabajador = ?"; $p[] = $trabId; }
        if ($tipo)   { $sql .= " AND ep.tipo_evento = ?";   $p[] = $tipo; }
        $rows = array_merge($rows, Database::fetchAll($sql . " LIMIT 500", $p));
    }

    // Visitantes
    if ($persona !== 'trabajador') {
        $sql = "SELECT
                  ep.id_evento, ep.fecha_hora, ep.tipo_evento,
                  'VISITANTE' AS tipo_persona,
                  v.nombre, v.dni, 'Visitante' AS nombre_area, v.empresa, '' AS cargo
                FROM eventos_personal ep
                JOIN visitantes v ON v.id_visitante = ep.id_visitante
                WHERE ep.tipo_persona = 'VISITANTE'
                  AND ep.tipo_evento IN ('DESAYUNO','ALMUERZO','CENA')
                  AND DATE(ep.fecha_hora) BETWEEN ? AND ?";
        $p = [$desde, $hasta];
        if ($tipo) { $sql .= " AND ep.tipo_evento = ?"; $p[] = $tipo; }
        $rows = array_merge($rows, Database::fetchAll($sql . " LIMIT 500", $p));
    }

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
    $horasProg = (int)(Database::fetchOne(
        "SELECT valor FROM config_sistema WHERE clave='horas_programadas_dia'"
    )['valor'] ?? HORAS_PROGRAMADAS_DEFAULT);

    $sql = "SELECT v.*,
                   ROUND(v.minutos_netos/60, 2)             AS horas_netas,
                   $horasProg                               AS horas_programadas,
                   ROUND((v.minutos_netos/60)-$horasProg,2) AS diferencia_horas,
                   CASE WHEN (v.minutos_netos/60)>=$horasProg
                        THEN 'extra' ELSE 'deficitaria' END AS tipo_diferencia
            FROM v_asistencia_diaria v
            WHERE v.fecha BETWEEN ? AND ?";
    $p = [$desde, $hasta];
    if ($area)   { $sql .= " AND v.id_trabajador IN (SELECT id_trabajador FROM trabajadores WHERE id_area=?)"; $p[]=$area; }
    if ($trabId) { $sql .= " AND v.id_trabajador=?"; $p[]=$trabId; }
    $sql .= " ORDER BY v.fecha DESC, v.nombre_completo ASC LIMIT 2000";
    Response::success(Database::fetchAll($sql, $p));
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
    Response::success(Database::fetchAll("SELECT * FROM areas WHERE activo=1 ORDER BY nombre_area"));
}

// ═══════════════════════════════════════════════════════════════
//  GET /api/export/*
// ═══════════════════════════════════════════════════════════════
if ($resource === 'export' && $method === 'GET') {
    require_once dirname(__DIR__) . '/exports/ExportController.php';
    ExportController::export($subRes);
}

Response::error("Ruta no encontrada: /$resource/$subRes", 404);
