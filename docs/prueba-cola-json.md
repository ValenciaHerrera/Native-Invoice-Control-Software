# Prueba funcional: formulario → JSON → SQLite

**Estado actual:** por defecto, los envíos requieren identidad corporativa autenticada por IIS. La opción exclusiva de pruebas `INVOICE_TEST_ALLOW_ANONYMOUS=1` permite generar como `anonymous` cuando falla esa verificación, mostrando un warning. Sin esa opción, el servidor PHP local sirve la pantalla y muestra qué configuración falta, pero no puede generar solicitudes. La suite funcional utiliza dos identidades de servidor simuladas y el modo anónimo explícito; no sustituye la validación corporativa real. Véase [Identidad corporativa](identidad-corporativa.md).

## Revisión del proyecto

No se encontraron archivos `AGENTS.md` aplicables. `Notes.txt` describe una intención de MVC/CRUD, login y facturas, pero lo implementado es `index.html`, `views/board.html` y Bootstrap local, sin framework, enrutador ni autenticación. Se incluye un runtime PHP 8.5.11 con PDO SQLite. `test.php` crea/inserta en una tabla `test` de `DB/invoices.db`; esa BD contiene solamente esa tabla de prueba y `sqlite_sequence`. La integración no la modifica.

`test_share/app/public/index.php` recibe `id` y `nombre` por POST para `update_proveedor`. Inicializa y consulta SQLite incluso desde el formulario; `Queue.php` genera un evento con `uniqid`, fecha, tipo, datos y un usuario tomado del entorno del proceso PHP (esto no acredita la identidad del visitante). Publica mediante un temporal que también termina en `.json`, sin comprobar escritura ni renombrado. `Database.php` crea `proveedores` y `eventos_log` y habilita WAL. La BD de referencia tiene ambas tablas vacías y hay dos JSON pendientes.

Ya existe `test_share/app/worker.php`: hace UPDATE de proveedor, inserta un evento y renombra el archivo. No hay transacción que reúna ambas escrituras, comprobación del movimiento, validación completa, recuperación idempotente ni bloqueo entre ejecuciones. Además, con `proveedores` vacía, el UPDATE no crea ningún registro. No se encontró un `.bat` propio de este flujo (el runtime trae un lanzador de PHAR, sin relación con la cola). Ejecutar periódicamente el procesador era una intención, no un servicio ni tarea programada implementados.

## Qué integra esta prueba

- `views/queue-test.php`: formulario PHP con Bootstrap local, identidad integrada obligatoria, validación de ID (1–999999999) y nombre (1–120 caracteres Unicode, sin controles), CSRF y redirección después del envío. No abre SQLite. Su mensaje confirma exclusivamente la generación del JSON pendiente.
- `index.html`: enlace «Prueba de solicitudes». El resto de las vistas conserva su flujo.
- `config/queue.php`: directorios y BD configurables mediante variables de entorno, con valores relativos al proyecto calculados como rutas absolutas; incluye la excepción de pruebas para `anonymous`, desactivada por defecto.
- `lib/QueueTest.php`: validación compartida, publicación completa y procesador con transacción y bloqueo.
- `lib/CorporateIdentity.php`: identidad obtenida exclusivamente de variables de IIS bajo el contrato configurado, sin usar cuentas del proceso ni datos del navegador.
- `scripts/process-queue.php` y `.bat`: ejecución manual o por tarea programada; el PHP del procesador solo acepta CLI.
- `scripts/serve-test.bat` y `test-router.php`: servidor de prueba local y lista de rutas públicas que impide descargar JSON, SQLite, scripts y configuración.
- `tests/queue-test.php`: verificaciones funcionales aisladas.
- `.gitignore`: excluye `runtime/`, incluyendo datos y evidencia de las pruebas.

Los archivos nuevos no requieren `test_share`; la referencia se conserva intacta. No se creó framework, autenticación propia, contraseña, tarea programada ni servicio instalado.

La BD aislada contiene `proveedores(id, nombre)` y `solicitudes(id, proveedor_id, nombre, usuario, fecha, payload, confirmado_en)`. Esta prueba crea el proveedor si aún no existe y aplica su nombre. La incorporación de más campos de factura o de tablas reales queda fuera de este incremento. La migración añade `usuario` a una BD de esta prueba ya existente, dejando NULL en filas antiguas cuya autoría nunca se verificó.

## Ejecutar en este equipo Windows

Desde PowerShell en la raíz del proyecto:

```powershell
.\scripts\serve-test.bat
```

Mantener esa consola abierta y visitar `http://127.0.0.1:8080/views/queue-test.php`, o abrir `http://127.0.0.1:8080/index.html` y seleccionar «Prueba de solicitudes». En este servidor local, el formulario estará bloqueado y explicará que falta autenticación integrada; activar una variable de entorno no autentica al visitante. Para enviar, alojar la ruta en el IIS corporativo configurado y verificado por TI según [la guía de identidad](identidad-corporativa.md). Allí se puede enviar, por ejemplo, ID `1` y nombre `Proveedor de prueba`; la solicitud debe mostrarse pendiente. En otra consola del equipo procesador:

```powershell
.\scripts\process-queue.bat
```

Para habilitar únicamente el envío anónimo de esta prueba local, detener el servidor y ejecutar `$env:INVOICE_TEST_ALLOW_ANONYMOUS = '1'` antes de volver a iniciar `scripts/serve-test.bat`. Se mostrará un warning y el servidor generará `usuario: anonymous`. La opción no autentica al visitante ni permite elegir su nombre; si existe una identidad verificada, esa identidad tiene prioridad. La guía de identidad explica cómo desactivar la opción y comprobar nuevamente el bloqueo estricto.

El `.bat` invoca el PHP ya incluido y carga explícitamente PDO SQLite con su ruta absoluta; no depende del `php.ini`, del directorio de trabajo ni de `test_share`. Salidas: `0` sin fallos, `1` algún error, `2` otro procesador tiene el bloqueo. «Confirmado y archivado» significa que SQLite confirmó y el archivo fue movido. La pantalla de envío no es una consulta de estado posterior.

Sin variables de entorno, se usan:

| Contenido | Ubicación desde la raíz |
| --- | --- |
| JSON pendientes | `runtime/queue/pending/*.json` |
| JSON confirmados | `runtime/queue/processed/*.json` |
| JSON inválidos para revisión | `runtime/queue/errors/*.json` |
| Errores del procesador | `runtime/queue/errors/worker.log` |
| BD SQLite de prueba | `runtime/data/queue-test.sqlite` |
| Bloqueo exclusivo | `runtime/queue/pending/worker.lock` |

Las carpetas se crean cuando se necesitan. En un equipo local, esos valores permiten ejecutar la prueba sin configuración. Para cambiar la ubicación, configurar rutas absolutas en el entorno del proceso:

```powershell
$env:INVOICE_QUEUE_DIR = '\\SERVIDOR\RECURSO\solicitudes-prueba'
$env:INVOICE_TEST_DB = Join-Path $env:LOCALAPPDATA 'NativeInvoiceControl\queue-test.sqlite'
.\scripts\process-queue.bat
```

`SERVIDOR` y `RECURSO` son marcadores, no una ubicación confirmada. `INVOICE_QUEUE_DIR` es la carpeta base que contiene `pending`, `processed` y `errors`; formulario y procesador deben apuntar a la misma cola. `INVOICE_TEST_DB` solo lo usa el procesador. La identidad que ejecuta una tarea programada puede tener otro `LOCALAPPDATA`; TI debe fijar una única ruta local y cuenta para evitar crear BDs distintas. Mantener estables ambas ubicaciones y respaldar la BD junto con su historial: reemplazarla por una BD vacía pierde la deduplicación de solicitudes ya confirmadas.

Detener el servidor local con Ctrl+C. No abrir la carpeta raíz con un servidor genérico sin reglas de acceso: el enrutador suministrado protege únicamente el servidor de prueba que lo invoca.

## Consistencia y recuperación

Cada solicitud recibe `req_` más 16 bytes aleatorios, una fecha UTC y `usuario` procedente del servidor autenticado, o el literal `anonymous` cuando falla la verificación y la excepción exclusiva de pruebas está activada. El identificador queda dentro del JSON y es la clave primaria de `solicitudes`; se conserva en todos los reintentos del procesador, junto con su autor. Cada envío nuevo genera otra solicitud. El formulario usa redirección y rotación del token para evitar reenvíos por recarga.

La publicación escribe un `.tmp` en el mismo directorio, verifica escritura completa, `fflush` y `fsync`, cierra y renombra a `.json`. El procesador solo enumera `.json`. Esta técnica evita seleccionar temporales; no equivale a una garantía de durabilidad de cualquier servidor SMB ante cortes eléctricos. Los temporales huérfanos de un proceso terminado abruptamente no se procesan; TI puede revisarlos y retirarlos cuando confirme que no hay un envío activo.

Una ejecución mantiene `flock(LOCK_EX | LOCK_NB)` sobre `worker.lock` hasta terminar. La segunda sale con código 2. El sistema operativo libera el bloqueo al terminar o caer el proceso: la existencia del archivo no significa que haya un bloqueo activo. No borrar/reemplazar `worker.lock` durante ejecuciones. La prueba verificó exclusión en este Windows local; la semántica de bloqueo y renombrado del recurso SMB real sigue pendiente de validación. Usar un único equipo procesador designado, también si hay varios usuarios del formulario.

El procesador revalida el esquema, campos y formato del usuario; mediante consultas parametrizadas inserta la solicitud con su autor y actualiza/crea el proveedor en una única transacción. No obtiene el autor desde la cuenta del `.bat`. SQLite usa `synchronous=FULL`, journal `DELETE` y espera de bloqueo de 5 segundos. Mueve el JSON solo después del commit. Si falla el movimiento, deja el origen pendiente y registra el error; el siguiente intento reconoce la misma solicitud y solo vuelve a intentar el movimiento. No reaplica el nombre antiguo sobre cambios posteriores. Un ID confirmado con contenido diferente, incluido su usuario, se rechaza y conserva en errores. Los JSON anteriores sin usuario verificado van a errores; no se les asigna un autor por defecto. No se garantiza orden de negocio entre solicitudes distintas para un mismo proveedor; el último cambio efectivamente procesado establece su nombre.

Los JSON inválidos se mueven a errores sin insertar; los errores transitorios de lectura, BD o movimiento dejan los archivos pendientes. Ningún movimiento sobrescribe un archivo de destino existente. Si existe uno que impide archivar, revisar ambos antes de retirar el obstáculo y reejecutar. `worker.log` identifica archivo, fecha, clase y causa; si no se puede escribir el log, el error se imprime por stderr. Conservar esa salida desde la tarea programada. Corregir datos inválidos requiere revisión; una solicitud ya confirmada no se puede alterar conservando su ID. No trasladar archivos directamente a procesados para simular una confirmación.

## Validación ejecutada

```powershell
.\php-8.5.11\php.exe -n -d extension_dir=./php-8.5.11/ext -d extension=pdo_sqlite tests/queue-test.php
```

La suite usa una carpeta única bajo `runtime/tests/` y conserva la evidencia; no modifica las BDs originales. Inicia y termina servidores PHP temporales en loopback. Los casos de envío válido usan `tests/identity-router.php`, un doble de prueba con identidad fija por proceso: no está incluido en el enrutador real ni en `serve-test.bat`. Comprueba:

- Publicación de JSON completo y ausencia de temporales después del éxito; rechazo de ID inválido, arrays, nombre vacío, controles y longitud excesiva.
- Envío HTTP válido, código 303, estado pendiente sin acceso a SQLite, rechazo 422, CSRF 403 y recarga sin reenvío.
- Dos identidades simuladas y sesiones HTTP distintas conservadas en JSON, SQLite y archivos procesados. Los valores POST y cabeceras de autor falsificados se ignoran.
- El enrutador real bloquea GET/POST sin identidad corporativa verificable, incluso con cabeceras de autenticación y nombre enviados por el cliente.
- Con la excepción de pruebas habilitada: warning visible, envío HTTP con `anonymous`, conservación hasta SQLite y procesados, y reejecución sin duplicados. El usuario verificado sigue teniendo prioridad y desactivar la opción vuelve a bloquear nuevas solicitudes sin identidad.
- Migración de la BD anterior sin borrar registros ni inventar autores; rechazo de JSON sin `usuario`.
- Escritura bloqueada: excepción en publicación y respuesta HTTP 503 sin falso éxito.
- Inserción y cambio de proveedor, movimiento posterior y reejecución sin duplicados.
- Replay de solicitud antigua sin revertir un cambio posterior; conflicto de mismo ID con datos distintos.
- JSON malformado y datos inválidos conservados en errores, con registro útil.
- BD inválida: archivo permanece pendiente. Error provocado durante el cambio de proveedor: rollback de la inserción de solicitud y recuperación posterior.
- Commit confirmado con destino de archivo bloqueado: origen conservado, siguiente intento archiva sin duplicar.
- Segundo proceso PHP real rechazado por el bloqueo; recuperación después de liberar el bloqueo.
- Rutas internas rechazadas por el servidor de prueba.
- En Windows, ejecución real del `.bat` desde un directorio de trabajo diferente: procesa el JSON generado por HTTP y se reejecuta sin duplicados.

Todos estos escenarios pasaron en Windows con el PHP del proyecto. Los errores provocados aparecen en la salida como parte de las pruebas. También se verifican sintaxis PHP y whitespace del diff. No se ha probado IIS/AD con dos personas reales, el recurso compartido, una cuenta de servicio corporativa, caída de red ni un corte eléctrico.

## Condiciones que TI debe confirmar para la red local

`index.html` y `views/board.html` pueden abrirse como archivos estáticos (los recursos CDN requieren acceso a internet). `views/queue-test.php` necesita un servidor HTTP con PHP: abrirlo como `file://` no ejecuta PHP ni habilita escritura en una carpeta compartida. El navegador envía un POST y escribe el proceso PHP con los permisos de su cuenta. El servidor local incluido escucha solamente en `127.0.0.1`; no se ha habilitado acceso LAN. Un alojamiento PHP corporativo requiere confirmar un servicio autorizado y configurar su raíz pública/rutas para excluir cola, BD, configuración, librerías y scripts CLI.

TI debe designar **un equipo Windows procesador**, con PHP y PDO SQLite compatibles (se utiliza el runtime incluido), que ejecute manualmente el `.bat` o programe su invocación. Su cuenta necesita leer/listar pendientes, crear/adquirir el bloqueo, mover/eliminar orígenes y escribir procesados y errores; también lectura/escritura en la carpeta local de SQLite y sus archivos de journal. El servicio del formulario necesita crear/escribir/renombrar temporales en pendientes; permisos de NTFS y del recurso compartido deben permitirlo. Confirmar acceso UNC bajo la cuenta real de la tarea; no asumir que las unidades mapeadas de un usuario existen en una tarea o servicio. Coordinar permisos para los archivos que crea la cuenta web y mueve la cuenta del procesador.

SQLite debe quedar en **disco local del procesador**, con todas las conexiones en ese equipo. Las rutas UNC se rechazan; una unidad mapeada no se detecta automáticamente, por lo que TI debe verificarla. Si el proyecto se instala en un recurso compartido, hay que sobrescribir el valor predeterminado de BD con una ruta local antes de ejecutar. No colocar la BD en SMB para que varios equipos la abran: SQLite documenta riesgos de bloqueo y fallos en sistemas de archivos de red, y WAL no funciona entre hosts. Referencias oficiales: [SQLite sobre redes](https://www.sqlite.org/useovernet.html) y [WAL](https://www.sqlite.org/wal.html). La elección de `DELETE` no convierte SQLite compartido en una alternativa segura.

Se registra identidad corporativa solamente si TI confirma el contrato de Windows Authentication en IIS y habilita el adaptador en el entorno del servicio PHP. La cuenta del servicio no representa al visitante; ninguna cuenta del proceso se usa como autor. El `.bat` conserva la identidad que ya lleva el JSON y no necesita autenticar al autor nuevamente en AD. TI debe confirmar autenticación con dos personas reales, restricciones de acceso al formulario/cola, copias de seguridad, retención de JSON y logs, espacio disponible y monitoreo de errores. Sin autenticación integrada verificable, el formulario no genera solicitudes salvo que se active expresamente la excepción exclusiva de pruebas para `anonymous`. Mantenerla desactivada para el despliegue corporativo. Esta integración no instala infraestructura ni asume su autorización.
