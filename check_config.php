<?php
/**
 * Verificación simple de config.php
 * Uso: php check_config.php
 */

echo "=== Verificación de config.php ===\n\n";

// 1. Verificar archivo existe
$config_path = __DIR__ . '/config.php';
echo "1. Archivo config.php\n";
echo "   Path: $config_path\n";

if (!file_exists($config_path)) {
    echo "   ❌ NO EXISTE\n";
    exit(1);
}
echo "   ✅ Existe\n";
echo "   Size: " . filesize($config_path) . " bytes\n";
echo "   Permisos: " . substr(sprintf('%o', fileperms($config_path)), -4) . "\n\n";

// 2. Intentar cargar
echo "2. Cargando config.php...\n";
try {
    require_once $config_path;
    echo "   ✅ Cargado sin errores\n\n";
} catch (Throwable $e) {
    echo "   ❌ Error al cargar: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Verificar constantes
echo "3. Constantes definidas:\n";

$required_constants = [
    'ANTHROPIC_API_KEY',
    'ENCRYPTION_KEY',
    'CLAUDE_MODEL',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'SHEET_CSV_URL',
    'JSON_FILE_REFERENCE',
    'WORKER_SECRET',
];

foreach ($required_constants as $const) {
    echo "   - $const: ";
    
    if (!defined($const)) {
        echo "❌ NO DEFINIDA\n";
        continue;
    }
    
    $value = constant($const);
    $length = strlen($value);
    
    // Mostrar info según tipo
    if ($const === 'WORKER_SECRET') {
        echo "✅ Definida ($length caracteres)";
        if ($length < 20) {
            echo " ⚠️ Muy corta (mínimo 20)";
        }
        echo "\n";
    } elseif ($const === 'ANTHROPIC_API_KEY') {
        echo "✅ Definida ($length caracteres)";
        if ($length < 30) {
            echo " ⚠️ Muy corta";
        }
        echo "\n";
    } elseif (in_array($const, ['GOOGLE_CLIENT_SECRET', 'ENCRYPTION_KEY'])) {
        echo "✅ Definida ($length caracteres)\n";
    } else {
        echo "✅ Definida: " . substr($value, 0, 50);
        if ($length > 50) echo "...";
        echo "\n";
    }
}

echo "\n=== RESULTADO ===\n";

if (defined('WORKER_SECRET')) {
    $secret_len = strlen(WORKER_SECRET);
    if ($secret_len >= 20) {
        echo "✅ WORKER_SECRET configurado correctamente ($secret_len caracteres)\n";
    } else {
        echo "⚠️ WORKER_SECRET muy corto: $secret_len caracteres (mínimo 20)\n";
        echo "   Valor actual: " . WORKER_SECRET . "\n";
        echo "   Recomendado: wk_7f3a9b2e1d4c8f6a0e5b3d7c9a2f4e8b (43 caracteres)\n";
    }
} else {
    echo "❌ WORKER_SECRET NO DEFINIDA\n";
    echo "   Agregar a config.php:\n";
    echo "   define('WORKER_SECRET', 'wk_7f3a9b2e1d4c8f6a0e5b3d7c9a2f4e8b');\n";
}

echo "\n";
