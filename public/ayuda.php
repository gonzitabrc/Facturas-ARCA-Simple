<?php
declare(strict_types=1);

require __DIR__ . '/../src/App.php';

use Facturador\App;
use Facturador\Seguridad\Auth;

App::iniciar();

// La ayuda se lee sin iniciar sesión: ahí están las condiciones de uso, y quien
// las acepta tiene que poder leerlas antes de entrar. Lo del emisor (CUIT, alias
// y vencimiento del certificado) solo se muestra a quien está adentro.
$adentro = Auth::logueado() || !Auth::loginRequerido();
$emisor = $adentro ? App::emisor() : null;

$contenido = App::capturar(static function () use ($emisor): void { ?>
    <nav class="indice tarjeta">
        <strong>En esta página</strong>
        <ul>
            <li><a href="#alcances"><strong>Condiciones de uso y responsabilidad</strong> (leer antes de usar)</a></li>
            <li><a href="#manual">Cómo facturar: manual de uso</a></li>
            <li><a href="#certificado">Certificado de ARCA: primera vez y renovación</a></li>
            <li><a href="#punto-venta">Punto de venta exclusivo para la app</a></li>
            <li><a href="#homologacion">Homologación y producción</a></li>
            <li><a href="#renovar">Renovación: qué cambia</a></li>
            <li><a href="#problemas">Problemas frecuentes</a></li>
            <li><a href="#openssl">Hacerlo a mano con OpenSSL</a></li>
        </ul>
    </nav>

    <section id="manual" class="tarjeta">
        <h2>Cómo facturar: manual de uso</h2>
        <p>
            El circuito de todos los días son cuatro pasos: llenar la planilla, subirla, emitir y descargar.
            Lo de abajo del todo (certificado, punto de venta) se hace una sola vez.
        </p>

        <h3>Una sola vez, antes de empezar</h3>
        <ol class="pasos">
            <li><a href="configuracion.php">Configuración</a>: CUIT, razón social, domicilio, condición de IVA y punto de venta.</li>
            <li><a href="#certificado">Certificado de ARCA</a>: generar el pedido, subirlo a ARCA, autorizar el alias y subir el <code>.crt</code>.</li>
            <li><a href="#punto-venta">Punto de venta exclusivo</a>, que no se use desde ningún otro lado.</li>
        </ol>

        <h3>Paso 1 · Llenar la planilla</h3>
        <p>
            <a class="boton" href="subir.php?descargar=plantilla">Descargar la plantilla (.xlsx)</a>
            <span class="tenue">Se puede bajar sin iniciar sesión, para ver qué hay que completar.</span>
        </p>
        <ol class="pasos">
            <li>Descargar la plantilla con el botón de arriba (o pedirle a tu sistema de gestión que exporte con esas columnas).</li>
            <li><strong>Una fila = una factura.</strong> No hace falta llenar todo: alcanza con lo obligatorio.</li>
            <li>
                Lo que no puede faltar:
                <ul class="lista">
                    <li><strong>Referencia</strong> (<code>id_externo</code>): el número de la operación en tu sistema. Es lo que evita facturar dos veces lo mismo.</li>
                    <li><strong>Tipo de comprobante</strong>: C si sos monotributista (11), A o B si sos responsable inscripto (1 o 6).</li>
                    <li><strong>Concepto</strong>: 1 productos, 2 servicios, 3 ambos. Con 2 o 3 hay que poner el período (desde, hasta) y el vencimiento de pago.</li>
                    <li><strong>Fecha</strong> del comprobante.</li>
                    <li><strong>Documento del cliente</strong>: CUIT (80), DNI (96) o consumidor final sin identificar (99, con documento 0).</li>
                    <li><strong>Condición de IVA del cliente</strong>: la pide ARCA y desde el 1/12/2026 rechaza la factura si falta.</li>
                    <li><strong>Descripción</strong>, <strong>importe</strong> y <strong>total</strong>. En factura C el importe es el total; en A y B va el neto más la alícuota (21, 10,5, 27 o 0).</li>
                </ul>
            </li>
            <li>Las fechas pueden ir como <code>31/12/2026</code> o <code>2026-12-31</code>, y los decimales con coma o con punto.</li>
        </ol>

        <h4>Las columnas, una por una</h4>
        <p class="tenue">
            La plantilla trae además una hoja <em>Instrucciones</em> y una hoja <em>Códigos</em> con los
            tipos de comprobante y las condiciones de IVA. No hace falta reordenar ni renombrar nada:
            la app tolera mayúsculas, acentos y algunos nombres alternativos.
        </p>
        <table class="tabla">
            <thead><tr><th>Columna</th><th>Qué va</th></tr></thead>
            <tbody>
            <tr><td><code>id_externo</code></td><td><strong>Obligatoria.</strong> La referencia de tu sistema (número de operación, de remito, lo que uses). Es lo que evita facturar dos veces lo mismo.</td></tr>
            <tr><td><code>punto_venta</code></td><td>El punto de venta configurado en la app. Si se deja vacía, se usa ese.</td></tr>
            <tr><td><code>tipo_cbte</code></td><td><strong>Obligatoria.</strong> 11 factura C · 6 factura B · 1 factura A · 13, 8 o 3 notas de crédito · 12, 7 o 2 notas de débito.</td></tr>
            <tr><td><code>concepto</code></td><td><strong>Obligatoria.</strong> 1 productos · 2 servicios · 3 productos y servicios.</td></tr>
            <tr><td><code>fecha</code></td><td><strong>Obligatoria.</strong> Fecha del comprobante.</td></tr>
            <tr><td><code>doc_tipo</code></td><td><strong>Obligatoria.</strong> 80 CUIT · 96 DNI · 99 consumidor final sin identificar.</td></tr>
            <tr><td><code>doc_nro</code></td><td><strong>Obligatoria.</strong> El número del documento. Con tipo 99 va 0.</td></tr>
            <tr><td><code>condicion_iva_receptor</code></td><td><strong>Obligatoria.</strong> Condición de IVA del cliente (1 responsable inscripto, 4 exento, 5 consumidor final, 6 monotributo). ARCA rechaza el comprobante si falta.</td></tr>
            <tr><td><code>razon_social</code></td><td>Nombre o razón social del cliente: sale impreso en el comprobante.</td></tr>
            <tr><td><code>domicilio</code></td><td>Domicilio del cliente, también para el PDF.</td></tr>
            <tr><td><code>email</code></td><td>De referencia, para tu control.</td></tr>
            <tr><td><code>descripcion</code></td><td><strong>Obligatoria.</strong> Lo que se está facturando, como querés que salga impreso.</td></tr>
            <tr><td><code>neto_gravado</code></td><td>Importe neto. En factura C es el importe total.</td></tr>
            <tr><td><code>alicuota_iva</code></td><td>21, 10.5, 27, 5, 2.5 o 0. En factura C va vacía.</td></tr>
            <tr><td><code>no_gravado</code></td><td>Importe no gravado, si hay. Si no, 0.</td></tr>
            <tr><td><code>exento</code></td><td>Importe exento, si hay. Si no, 0.</td></tr>
            <tr><td><code>total</code></td><td><strong>Obligatoria.</strong> Total del comprobante. Tiene que coincidir con neto + IVA + no gravado + exento.</td></tr>
            <tr><td><code>moneda</code></td><td><code>PES</code> salvo que factures en otra moneda.</td></tr>
            <tr><td><code>cotizacion</code></td><td>1 en pesos. En otra moneda, la cotización que corresponda.</td></tr>
            <tr><td><code>serv_desde</code> <code>serv_hasta</code> <code>vto_pago</code></td><td>Obligatorias con concepto 2 o 3: período del servicio y vencimiento del pago.</td></tr>
            <tr><td><code>asoc_tipo</code> <code>asoc_pto_vta</code> <code>asoc_nro</code></td><td>Obligatorias en notas de crédito y débito: tipo, punto de venta y número del comprobante que se está corrigiendo.</td></tr>
            </tbody>
        </table>

        <h3>Paso 2 · Subir y revisar</h3>
        <ol class="pasos">
            <li>En <a href="index.php">Lotes</a> → <em>Subir archivo</em>.</li>
            <li>
                Aparece la vista previa: <strong>en verde las filas listas</strong> y
                <strong>en rojo las que hay que corregir</strong>, cada una con el motivo en castellano
                (“Fila 14: el CUIT no es válido”, “el total no coincide con neto + IVA”).
            </li>
            <li>
                Se emiten <strong>solo las verdes</strong>. Las rojas no se emiten ni se pierden: salen
                después en <code>errores.csv</code>.
            </li>
        </ol>

        <h3>Paso 3 · Emitir</h3>
        <ol class="pasos">
            <li>Tocar <em>Emitir</em>. La barra avanza factura por factura, pidiéndole el CAE a ARCA de una en una.</li>
            <li>Conviene no cerrar la pestaña. Si se cierra o se corta la luz, al volver al lote aparece <em>Continuar</em> y sigue donde quedó.</li>
            <li>Si ARCA deja de responder, el lote se <strong>pausa solo</strong>. No se pierde ni se duplica nada: antes de seguir, la app le pregunta a ARCA qué quedó emitido.</li>
        </ol>

        <h3>Paso 4 · Descargar</h3>
        <ol class="pasos">
            <li><strong><code>emitidas.csv</code></strong>: las columnas de la planilla más el número de comprobante, el CAE, el vencimiento del CAE y el link al PDF. Es lo que se carga de vuelta en el sistema de gestión.</li>
            <li><strong><code>pdfs.zip</code></strong>: todos los PDF del lote, para mandarlos a los clientes.</li>
            <li><strong><code>errores.csv</code></strong>: las filas que no salieron, con las mismas columnas de la planilla más el motivo. Se corrige, se borra la columna del motivo si querés, y se vuelve a subir tal cual.</li>
        </ol>

        <h3>Si algo no sale</h3>
        <table class="tabla">
            <thead><tr><th>Qué dice la app</th><th>Qué hacer</th></tr></thead>
            <tbody>
            <tr>
                <td>“ARCA no responde, probá en unos minutos”</td>
                <td>Esperar y tocar <em>Continuar</em>. Es un problema de ARCA, no del archivo.</td>
            </tr>
            <tr>
                <td>Una factura en “revisión manual”</td>
                <td>La app encontró en ARCA un comprobante con ese número que no coincide con la fila. Revisalo en <em>Mis Comprobantes</em> de ARCA antes de seguir.</td>
            </tr>
            <tr>
                <td>“Numeración desfasada”</td>
                <td>Alguien emitió con este punto de venta por fuera de la app. Ver <a href="#punto-venta">punto de venta exclusivo</a>.</td>
            </tr>
            <tr>
                <td>“Certificado vencido o no autorizado”</td>
                <td>Renovar el certificado: <a href="#certificado">los pasos están acá</a>.</td>
            </tr>
            <tr>
                <td>Una fila rechazada por datos</td>
                <td>El motivo dice qué corregir. Ese número de comprobante no se consumió: se corrige y se vuelve a subir.</td>
            </tr>
            </tbody>
        </table>

        <h3>Anular una factura: nota de crédito</h3>
        <p>
            Una factura emitida no se borra ni se edita: se anula con una <strong>nota de crédito</strong>,
            que es otro comprobante. Se hace con la misma planilla, una fila:
        </p>
        <ol class="pasos">
            <li>
                <strong>Tipo de comprobante:</strong> 13 si la factura era C, 8 si era B, 3 si era A
                (la nota de crédito es de la misma clase que la factura).
            </li>
            <li>
                <strong>Comprobante asociado:</strong> en <code>asoc_tipo</code> el tipo de la factura
                original (11, 6 o 1), en <code>asoc_pto_vta</code> su punto de venta y en
                <code>asoc_nro</code> su número. Los ves en la pantalla del lote, en la columna
                <em>Comprobante</em>: por ejemplo <code>0005-00000123</code> es punto de venta 5, número 123.
            </li>
            <li>
                <strong>El resto igual que la factura:</strong> mismo cliente, mismo importe y misma alícuota.
                Si querés anular solo una parte, poné el importe que corresponda.
            </li>
            <li>Subir la planilla y emitir, como cualquier otro lote.</li>
        </ol>
        <p class="tenue">
            La app no te deja emitir una nota de crédito sin comprobante asociado, y si la factura original la
            emitió esta app, verifica que haya quedado autorizada.
        </p>

        <h3>Tres cosas que la app nunca hace</h3>
        <ul class="lista">
            <li>Facturar dos veces la misma referencia (<code>id_externo</code>), aunque subas el mismo archivo de nuevo.</li>
            <li>Inventar un número de comprobante: siempre le pregunta a ARCA cuál es el último antes de emitir.</li>
            <li>Dar de baja un CAE ya otorgado. Un comprobante emitido se corrige con una nota de crédito, nunca borrándolo.</li>
        </ul>
    </section>

    <section id="certificado" class="tarjeta">
        <h2>Certificado de ARCA: primera vez y renovación</h2>
        <p>
            Cada CUIT que factura necesita su propio certificado digital. Dura <strong>2 años</strong> y lleva
            unos 15 minutos. Los pasos son los mismos la primera vez y en cada renovación: la única diferencia
            es que al renovar se usa un <strong>alias nuevo</strong> y el certificado viejo sigue funcionando
            hasta que venza.
        </p>
        <?php if ($emisor !== null && ($emisor['crt_vence'] ?? null) !== null): ?>
            <p class="aviso <?= ((int) floor((strtotime((string) $emisor['crt_vence']) - time()) / 86400)) <= 30 ? 'atencion' : 'ok' ?>">
                El certificado de este CUIT vence el <?= App::esc(date('d/m/Y', (int) strtotime((string) $emisor['crt_vence']))) ?>.
            </p>
        <?php endif; ?>

        <h3>Antes de empezar</h3>
        <ul class="lista">
            <li>CUIT y nombre o razón social, <strong>tal como figuran en ARCA</strong>.</li>
            <li>
                Clave fiscal propia, nivel 3 o superior. Cada titular entra con la suya:
                la clave fiscal es personal y no se comparte.
            </li>
            <li>
                Un alias nuevo para el certificado, solo letras y números, sin espacios ni símbolos.
                Conviene que diga cuándo vence, por ejemplo <code>octubre2028</code>.
            </li>
            <li>Saber si el certificado es para <a href="#homologacion">homologación o producción</a>.</li>
        </ul>

        <p class="aviso ok">
            <strong>¿Ya tenés el certificado?</strong>
            Entonces no hace falta nada de esto: en <a href="configuracion.php">Configuración</a> →
            <em>Subir o actualizar el certificado</em> subís el <code>.crt</code> y su <code>.key</code>
            juntos y listo. Sirve igual si el certificado lo usás en otro sistema: se puede compartir.
            Los pasos de abajo son para cuando hay que pedirle uno nuevo a ARCA.
        </p>

        <h3>Qué es el “alias”</h3>
        <p>
            El alias es el nombre con el que el certificado figura en ARCA, en
            <em>Administración de Certificados Digitales</em>. Lo elegís vos cuando lo pedís (por ejemplo
            <code>octubre2028</code>) y queda grabado adentro del archivo <code>.crt</code>, así que la app
            lo lee sola y te lo muestra en Configuración. No es una clave ni un dato secreto: es solo cómo se
            llama ese certificado en la lista.
        </p>

        <h3>Subir, actualizar o quitar el certificado</h3>
        <ul class="lista">
            <li>
                <strong>Subir o actualizar:</strong> se suben el <code>.crt</code> y el <code>.key</code>
                juntos (y la contraseña de la clave, si tiene). La app comprueba que sean pareja, que el CUIT
                del certificado sea el del emisor y que no esté vencido. Del certificado anterior queda una
                copia de respaldo en el servidor.
            </li>
            <li>
                <strong>Un mismo certificado puede estar en dos sistemas.</strong> Lo único que hay que saber:
                ARCA entrega un solo ticket de acceso por certificado y dura 12 horas, así que si los dos
                sistemas facturan muy seguido, uno puede quedar esperando. Para uso intensivo conviene un
                alias propio por sistema.
            </li>
            <li>
                <strong>Quitar:</strong> borra del servidor el certificado y la clave privada de ese CUIT
                (también las copias de respaldo). En ARCA el certificado sigue existiendo y se puede volver a
                subir cuando quieras. Sirve, por ejemplo, para prestar un certificado para una prueba y
                después sacarlo.
            </li>
        </ul>

        <h3>Paso 1 · Generar el pedido (CSR) en la app</h3>
        <ol class="pasos">
            <li>Entrar a <a href="configuracion.php">Configuración</a> y chequear que el CUIT y la razón social estén bien escritos.</li>
            <li>Tocar <em>Generar pedido de certificado</em> y escribir el alias.</li>
            <li>Descargar el archivo <code>.csr</code> que queda disponible: es el único que se sube a ARCA.</li>
        </ol>
        <p class="tenue">
            La clave privada la genera y la guarda la app, cifrada, en el servidor. No se descarga,
            no se manda por mail ni por chat, y no se sube a ARCA.
        </p>

        <h3>Paso 2 · Subir el pedido a ARCA</h3>
        <p>Lo hace el titular con su propia clave fiscal.</p>
        <ol class="pasos">
            <li>
                Entrar a ARCA y abrir <strong>Administración de Certificados Digitales</strong>.
                Si no aparece, agregarlo desde <strong>Administrador de Relaciones → Adherir servicio</strong>.
            </li>
            <li>Tocar <strong>Agregar alias</strong>.</li>
            <li>Escribir el mismo alias del Paso 1 (por ejemplo <code>octubre2028</code>).</li>
            <li>Elegir el archivo <code>.csr</code> y tocar <strong>Agregar alias</strong>.</li>
            <li>Entrar al alias recién creado y tocar <strong>Descargar</strong> al lado del certificado.</li>
            <li>Guardar el archivo <code>.crt</code> que se descarga.</li>
        </ol>

        <h3>Paso 3 · Autorizar el alias para facturar</h3>
        <p>
            Un alias nuevo no tiene permisos. Sin este paso ARCA responde “no autorizado” aunque el
            certificado sea válido.
        </p>
        <ol class="pasos">
            <li>En ARCA, abrir <strong>Administrador de Relaciones</strong>.</li>
            <li>Tocar <strong>Nueva Relación</strong>.</li>
            <li>En <em>Servicio</em>: <strong>Buscar → ARCA → Web Services → Facturación Electrónica</strong>.</li>
            <li>
                En <em>Representante</em>: <strong>Buscar</strong> y, en el desplegable
                <strong>Computador Fiscal</strong>, elegir el alias nuevo.
                No cargar un CUIT de persona.
            </li>
            <li>Tocar <strong>Confirmar</strong> en la ventana y después <strong>Confirmar</strong> abajo.</li>
            <li>
                Aparece el formulario F.3283. Si la dirección de la página termina en
                <code>aceptada=True</code>, quedó bien.
            </li>
        </ol>

        <h3>Paso 4 · Subir el certificado a la app</h3>
        <ol class="pasos">
            <li>Volver a <a href="configuracion.php">Configuración</a> y subir el archivo <code>.crt</code>.</li>
            <li>
                La app verifica que el certificado sea pareja de la clave privada que generó en el Paso 1,
                lee el vencimiento y lo muestra en pantalla. Si no son pareja, lo rechaza: suele ser que se
                mezclaron archivos de dos alias o de dos CUIT.
            </li>
        </ol>

        <h3>Paso 5 · Probar</h3>
        <ol class="pasos">
            <li>En <a href="configuracion.php">Configuración</a>, tocar <em>Probar conexión con ARCA</em>.</li>
            <li>Si el ambiente es homologación, emitir una factura de prueba antes de pasar a producción.</li>
            <li>Anotar en el calendario la renovación, unas semanas antes del vencimiento (la app también avisa 30 días antes).</li>
        </ol>
    </section>

    <section id="punto-venta" class="tarjeta">
        <h2>Punto de venta exclusivo para la app</h2>
        <p>
            La app necesita un punto de venta propio, que no se use en ningún otro lado: la numeración de
            comprobantes es consecutiva y si alguien emite con el mismo punto de venta desde el portal de ARCA,
            la numeración se desfasa y los lotes se pausan.
        </p>
        <ol class="pasos">
            <li>En ARCA: <strong>Administración de puntos de venta y domicilios</strong>.</li>
            <li>
                Crear uno nuevo del tipo
                <strong>“Factura Electrónica - Monotributo / RI - Web Services”</strong>.
            </li>
            <li>Cargar ese número en <a href="configuracion.php">Configuración</a>.</li>
            <li>No emitir nunca desde el portal con ese punto de venta.</li>
        </ol>
    </section>

    <section id="homologacion" class="tarjeta">
        <h2>Homologación y producción</h2>
        <p>
            Son dos mundos separados: certificado distinto, numeración distinta y comprobantes sin validez
            fiscal en homologación.
        </p>
        <ul class="lista">
            <li>
                <strong>Homologación (pruebas):</strong> el certificado se pide en
                <strong>WSASS</strong> (autogestión de certificados de homologación) en vez de
                Administración de Certificados Digitales. El resto de los pasos es igual.
            </li>
            <li>
                <strong>Producción:</strong> el circuito de los Pasos 2 y 3 de arriba, con clave fiscal.
            </li>
            <li>
                En la app, el ambiente se elige en <a href="configuracion.php">Configuración</a>.
                Al cambiar de ambiente hay que subir el certificado de ese ambiente.
            </li>
        </ul>
    </section>

    <section id="renovar" class="tarjeta">
        <h2>Renovación: qué cambia</h2>
        <ul class="lista">
            <li><strong>Alias nuevo siempre.</strong> Mientras el nuevo no esté probado, el viejo sigue emitiendo.</li>
            <li><strong>Mismos Pasos 1 a 5.</strong> Generar CSR, subirlo, autorizar el alias, subir el <code>.crt</code>, probar.</li>
            <li>
                <strong>No hace falta tocar el punto de venta</strong> ni la numeración: el certificado no
                cambia nada de eso.
            </li>
            <li>
                <strong>Si algo falla</strong>, el certificado anterior sigue sirviendo hasta su fecha de
                vencimiento: hay margen para resolverlo sin dejar de facturar.
            </li>
        </ul>
    </section>

    <section id="problemas" class="tarjeta">
        <h2>Problemas frecuentes</h2>
        <table class="tabla">
            <thead>
            <tr><th>Qué pasa</th><th>Qué hacer</th></tr>
            </thead>
            <tbody>
            <tr>
                <td>El alias no aparece en <em>Computador Fiscal</em> (Paso 3)</td>
                <td>Falta terminar el Paso 2: el certificado tiene que estar descargado y el alias figurar como válido.</td>
            </tr>
            <tr>
                <td>ARCA responde “no autorizado” con el certificado nuevo</td>
                <td>Falta el Paso 3, o se eligió otro alias u otro servicio. Revisarlo en Administrador de Relaciones.</td>
            </tr>
            <tr>
                <td>La app rechaza el <code>.crt</code> al subirlo</td>
                <td>El certificado no es pareja de la clave privada: se mezclaron archivos de dos alias o de dos CUIT. Descargá de nuevo el <code>.crt</code> del alias correcto.</td>
            </tr>
            <tr>
                <td>ARCA no acepta el alias</td>
                <td>Usar solo letras y números, sin espacios ni símbolos.</td>
            </tr>
            <tr>
                <td>“Numeración desfasada”</td>
                <td>Alguien emitió con este punto de venta por fuera de la app. Ver <a href="#punto-venta">punto de venta exclusivo</a>.</td>
            </tr>
            <tr>
                <td>Se perdió la clave privada del servidor</td>
                <td>No se recupera. Se genera un pedido nuevo con un alias nuevo (Paso 1) y se repite el circuito.</td>
            </tr>
            </tbody>
        </table>
    </section>

    <section id="openssl" class="tarjeta">
        <h2>Hacerlo a mano con OpenSSL</h2>
        <p>
            No hace falta para usar la app: el Paso 1 ya genera la clave y el pedido. Sirve si querés generar
            los archivos por fuera, o verificar un certificado que ya tenés.
            En Windows con Git instalado, OpenSSL viene en
            <code>C:\Program Files\Git\mingw64\bin\openssl.exe</code>.
        </p>

        <h3>Generar clave y pedido</h3>
        <pre><code>$o = "C:\Program Files\Git\mingw64\bin\openssl.exe"
&amp; $o genrsa -out perez_octubre2028.key 2048
&amp; $o req -new -key perez_octubre2028.key `
   -subj "/C=AR/O=Juan Perez/CN=octubre2028/serialNumber=CUIT 20123456789" `
   -out perez_octubre2028.csr</code></pre>
        <p class="tenue">
            Cambiar el nombre de los archivos, la razón social, el alias y el CUIT (sin guiones).
            El <code>.csr</code> es el que se sube a ARCA; el <code>.key</code> no se sube ni se manda por mail.
        </p>

        <h3>Revisar el pedido</h3>
        <pre><code>&amp; $o req -in perez_octubre2028.csr -noout -verify -subject</code></pre>
        <p class="tenue">Tiene que decir <code>verify OK</code> y mostrar bien la razón social, el alias y el CUIT.</p>

        <h3>Comprobar que certificado y clave son pareja</h3>
        <pre><code>&amp; $o x509 -in octubre2028.crt -noout -subject -dates
&amp; $o x509 -in octubre2028.crt -noout -pubkey | &amp; $o sha256
&amp; $o pkey -in perez_octubre2028.key -pubout | &amp; $o sha256</code></pre>
        <p class="tenue">
            La primera línea muestra el alias, el CUIT y el vencimiento (<code>notAfter</code>).
            Las dos últimas tienen que dar exactamente el mismo código; si dan distinto, los archivos son de
            dos personas o de dos alias distintos.
        </p>

        <h3>Si los archivos están en otro servidor</h3>
        <ul class="lista">
            <li>Mover el <code>.key</code> solo por SFTP o SCP (WinSCP, FileZilla en modo SFTP). Nunca por FTP común: la clave viajaría sin cifrar.</li>
            <li>En Linux, dejar el <code>.key</code> legible solo por el sistema de facturación: <code>chmod 600 archivo.key</code>.</li>
            <li>No dejar claves privadas en carpetas que se sincronizan a la nube (OneDrive, Google Drive).</li>
        </ul>
    </section>

    <section id="alcances" class="tarjeta destacada">
        <h2>Condiciones de uso y responsabilidad</h2>

        <p class="aviso atencion">
            <strong>El uso de esta aplicación implica la aceptación de estas condiciones.</strong>
            Si no estás de acuerdo con alguno de los puntos de abajo, no la uses.
        </p>

        <h3>Se entrega tal cual está</h3>
        <p>
            La aplicación se entrega y se usa <strong>tal cual está</strong>, con las funciones que tiene hoy
            y sin garantías de ningún tipo: ni de que esté libre de errores, ni de que esté siempre
            disponible, ni de que sirva para un fin distinto del que describe esta ayuda.
        </p>

        <h3>La responsabilidad es de quien factura</h3>
        <ul class="lista">
            <li>
                <strong>Todo lo que se emite con esta aplicación es responsabilidad exclusiva del
                contribuyente que factura.</strong> Los datos cargados, los comprobantes emitidos, sus
                importes, sus receptores y sus consecuencias fiscales son suyos.
            </li>
            <li>
                La aplicación <strong>no reemplaza al contador</strong> ni da asesoramiento impositivo. Qué
                comprobante corresponde, con qué alícuota, a quién y cuándo, lo decide el contribuyente.
            </li>
            <li>
                Es responsabilidad del contribuyente revisar los comprobantes emitidos y corregir lo que haga
                falta por los medios que prevé ARCA (por ejemplo, una nota de crédito).
            </li>
            <li>
                También son suyos los trámites en ARCA (clave fiscal, certificado, punto de venta) y el
                servidor donde corre la aplicación: hosting, dominio, SSL, backups y permisos.
            </li>
        </ul>

        <h3>Exención de responsabilidad del desarrollador</h3>
        <p>
            <strong>DDW y el desarrollador quedan eximidos de toda responsabilidad</strong> por cualquier
            daño, perjuicio, multa, sanción, intereses, pérdida de datos, lucro cesante o reclamo de terceros
            que surja del uso —o de la imposibilidad de uso— de esta aplicación. Esto incluye, entre otros
            casos: datos mal cargados, comprobantes emitidos por error o por duplicado, caídas o cambios de
            ARCA, caídas o fallas del servidor, y cambios de normativa.
        </p>

        <h3>Soporte</h3>
        <p>
            <strong>El soporte es un servicio pago.</strong> El código es libre y se puede descargar,
            instalar y usar sin pagar nada, pero usarlo no da derecho a soporte.
        </p>
        <ul class="lista">
            <li>
                <strong>Con la implementación contratada a DDW</strong>, está incluido
                <strong>un (1) mes de soporte</strong> desde la puesta en marcha. La implementación cubre:
                instalación y puesta en marcha en el servidor, trámites y gestiones en ARCA, configuración
                del emisor con la plantilla estándar, pruebas iniciales hasta dejarlo emitiendo, y ese mes
                de soporte.
            </li>
            <li>
                <strong>Pasado ese mes, toda solicitud o incidencia es con cargo</strong>, y se cotiza antes
                de empezar. Se tarifa <strong>por hora</strong>, según el tramo de horas de las
                <a href="https://ddw.com.ar/tarifas.html#desarrolloprogramacion-web-a-medida" target="_blank" rel="noopener">tarifas
                públicas de desarrollo a medida</a>.
            </li>
            <li>
                <strong>Sin implementación contratada no hay soporte incluido:</strong> cualquier consulta o
                intervención se cobra desde el primer día, con esas mismas tarifas.
            </li>
            <li>
                <strong>Certificado de ARCA:</strong> vence cada 2 años. La renovación corre por cuenta del
                contribuyente; si se quiere que la gestione DDW, se cotiza aparte.
            </li>
            <li>
                <strong>Hosting:</strong> se contrata aparte. Si el hosting es de DDW, los problemas del
                hosting se atienden <strong>sin cargo</strong>. Si está en otro proveedor, el servidor no
                entra en el soporte.
            </li>
            <li>
                <strong>No hay abono ni pago por uso:</strong> la aplicación no tiene costo mensual ni se
                cobra por comprobante emitido.
            </li>
            <li>
                Quedan fuera del soporte, también durante el mes incluido: cargar o corregir las planillas,
                los trámites que dependen de la clave fiscal del contribuyente, y los problemas provocados
                por cambios hechos a la aplicación por fuera.
            </li>
        </ul>

        <h3>Qué sí hace la aplicación</h3>
        <p>
            Para que quede claro de qué se habla: toma el Excel o CSV, valida los datos, le pide el CAE a
            ARCA con el certificado del emisor, genera los PDF con QR y devuelve un CSV con número, CAE y
            link a cada comprobante. Lo que busca asegurar es <strong>transparencia</strong>: que ninguna
            factura se pierda ni se duplique sin que se vea, y que después de un corte se le pregunte a ARCA
            qué quedó emitido antes de seguir.
        </p>
    </section>
<?php });

App::render('Ayuda', $contenido, ['avisoCert' => App::avisoCertificado($emisor)]);
