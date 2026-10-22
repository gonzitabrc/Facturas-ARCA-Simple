<?php
// Copiar a config.php y completar. NO subir config.php a ningún repositorio.
declare(strict_types=1);

return [
    // Clave para cifrar las claves privadas de los certificados.
    // Generar una vez con: php -r "echo bin2hex(random_bytes(32));"
    // Si se pierde, hay que regenerar CSR y certificados de todos los emisores.
    'app_key' => 'CAMBIAR_POR_64_CARACTERES_HEX',

    'app_url' => 'https://facturas.ejemplo.com.ar',   // sin barra final; se usa para armar url_pdf en el CSV

    // Zona horaria del emisor: define la fecha del comprobante que se manda a ARCA.
    'zona_horaria' => 'America/Argentina/Buenos_Aires',

    // Usuarios de la app. Generar hash con: php -r "echo password_hash('clave', PASSWORD_DEFAULT);"
    'usuarios' => [
        'admin' => '$2y$10$REEMPLAZAR_POR_HASH',
    ],

    // En false la app NO pide usuario ni clave: solo para probar en una máquina local
    // o para una instalación de demostración (ver 'modo_demo').
    // En una instalación que factura de verdad va SIEMPRE en true.
    'login_requerido' => true,

    // Modo demostración: la app se ve entera pero no deja hacer nada.
    // Bloquea todas las acciones (emitir, guardar, subir, tocar certificados) y
    // nunca llama a ARCA. Es lo que se publica para mostrar el sistema.
    'modo_demo' => false,

    // A dónde manda el botón "Contactanos" del cartel de demostración.
    'demo_contacto' => 'https://ddw.com.ar',

    'rutas' => [
        'storage' => __DIR__ . '/../storage',
        'db'      => __DIR__ . '/../storage/facturador.sqlite',
    ],

    'arca' => [
        'homo' => [
            'wsaa'  => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'wsfe'  => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL',
        ],
        'prod' => [
            'wsaa'  => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'wsfe'  => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL',
        ],
        'timeout_conexion_seg' => 15,
        'timeout_respuesta_seg' => 45,
    ],

    'reglas' => [
        // Monto a partir del cual un consumidor final debe identificarse (doc_tipo 99 no permitido).
        // ARCA lo actualiza por resolución: VERIFICAR el valor vigente antes de producción.
        'monto_identificacion_cf' => '0',   // '0' = desactivado hasta cargar el valor vigente
        'dias_tolerancia_productos' => 5,
        'dias_tolerancia_servicios' => 10,
        'tolerancia_total' => '0.01',
    ],

    'upload' => [
        'max_bytes' => 5 * 1024 * 1024,
        'extensiones' => ['xlsx', 'csv'],
    ],

    // Solo para pruebas en homologación: fuerza un error técnico cada N envíos
    // para probar el flujo INCIERTO → reconciliación. 0 = desactivado. NUNCA en producción.
    'simular_timeout_cada_n' => 0,
];
