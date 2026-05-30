<?php
/**
 * core/Response.php
 * Helpers para respuestas JSON de la API
 */

class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK'): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, mixed $data = null): never
    {
        self::json(['success' => false, 'message' => $message, 'data' => $data], $status);
    }

    public static function notFound(string $message = 'No encontrado'): never
    {
        self::error($message, 404);
    }

    public static function conflict(string $message): never
    {
        self::error($message, 409);
    }
}
?>