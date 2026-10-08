# Usuario corporativo en solicitudes

## Excepción exclusiva para pruebas

`INVOICE_TEST_ALLOW_ANONYMOUS=1` permite generar solicitudes cuando la verificación corporativa falla. Está **desactivada por defecto**; únicamente el valor exacto `1` habilita `config/queue.php::test_allow_anonymous`. No se activa mediante POST, cookies ni cabeceras.

Para probar desde PowerShell, en la consola que inicia el servidor:

```powershell
$env:INVOICE_TEST_ALLOW_ANONYMOUS = '1'
.\scripts\serve-test.bat
```

Abrir `http://127.0.0.1:8080/views/queue-test.php`. La verificación corporativa se ejecuta primero: si tiene éxito, se conserva el usuario verificado. Si falla, aparece un warning con la configuración que falta y se permite enviar con `"usuario": "anonymous"`. Este valor no se muestra como identidad corporativa verificada y no se puede editar. CSRF y validación de los campos siguen activos.

En otra consola, ejecutar `scripts/process-queue.bat` como antes. El procesador conserva `anonymous` en SQLite, payload y JSON procesado sin necesitar habilitar esta opción; el control afecta a la generación de nuevas solicitudes, no a la conservación de solicitudes ya generadas.

Para volver al bloqueo estricto, detener el servidor, quitar la variable y reiniciarlo:

```powershell
Remove-Item Env:INVOICE_TEST_ALLOW_ANONYMOUS -ErrorAction SilentlyContinue
.\scripts\serve-test.bat
```

Sin esta opción, la ausencia de identidad vuelve a bloquear GET/POST y no genera JSON. No habilitarla en el despliegue corporativo. La suite comprobó ambos modos por HTTP, el warning, prioridad del usuario verificado, rechazo de usuarios falsificados y conservación/reintento de `anonymous` hasta SQLite.

## Qué hacía la referencia y por qué se reemplaza

`test_share/app/lib/Queue.php::getUsuario()` consulta `$_SERVER['USERNAME']`, `$_SERVER['USER']` y `getenv('USERNAME')`. Son valores del proceso/entorno, no la identidad autenticada de cada petición HTTP. Si varias personas usan un servidor PHP común, puede registrar para todas la cuenta que ejecuta PHP. Su comentario sugiere `AUTH_USER`/`LOGON_USER`, pero el código nunca consulta esas variables. El acceso SMB a una carpeta compartida no autentica automáticamente las peticiones HTTP.

La integración anterior deliberadamente no inventaba identidad. Ahora `lib/CorporateIdentity.php` proporciona un adaptador explícito para **Windows Authentication de IIS**, que debe existir y ser validado por TI. No se encontró IIS, configuración de autenticación integrada ni un proveedor corporativo accesible en este repositorio. No se ha instalado ni habilitado un servicio nuevo.

## Contrato con TI y configuración requerida

Antes de habilitar el adaptador, TI debe:

1. Confirmar que IIS es el servidor corporativo autorizado para alojar esta aplicación y configurar PHP/FastCGI. Las rutas públicas deben excluir scripts CLI, `lib`, `config`, `tests`, `runtime` y ambas BDs; el enrutador local no configura IIS.
2. Instalar/habilitar el rol **Windows Authentication** para el sitio/aplicación. Deshabilitar **Anonymous Authentication** y otros mecanismos como Basic para esta ruta. Restringir el acceso a las cuentas corporativas autorizadas, no a cuentas Windows locales. No es necesario que la cuenta del pool sea la del visitante: los permisos para escribir archivos siguen correspondiendo al servicio.
3. Confirmar dominio/AD, URL del sitio, proveedores Negotiate/NTLM y las políticas de navegador para autenticación integrada en la intranet. Si se usa Kerberos, TI debe revisar el nombre del sitio/SPN y la cuenta del servicio. Utilizar la URL corporativa y el transporte aprobados por TI.
4. Comprobar en PHP que IIS entrega `AUTH_USER` como `DOMINIO\usuario` o `usuario@dominio`, `AUTH_TYPE` como Negotiate/NTLM/Kerberos y `SERVER_SOFTWARE` como Microsoft-IIS. Si existen `REMOTE_USER` o `LOGON_USER`, deben coincidir con `AUTH_USER` (sin distinguir mayúsculas). Una configuración que mapee el usuario a otra cuenta se rechaza para evitar atribución errónea.
5. Evitar reescrituras que copien cabeceras del cliente (`Auth-User`, `Remote-User`, `X-Remote-User`, etc.) a variables autenticadas. El adaptador confía en las variables que IIS produce después de autenticar: no implementa ni verifica criptográficamente el protocolo Windows por sí mismo. No debe publicarse una vía alternativa que eluda IIS.
6. Una vez comprobado lo anterior, configurar **en el entorno del proceso web PHP** `INVOICE_CORPORATE_AUTH=iis-windows` y reiniciar/reciclar el servicio si es necesario. Cambiar esta variable no activa Windows Authentication en IIS. Su valor predeterminado es `disabled`.

Las variables de cola y BD siguen documentadas en [la guía del flujo](prueba-cola-json.md). El procesador no necesita `INVOICE_CORPORATE_AUTH`: lee y conserva el autor del JSON. TI debe impedir que usuarios finales escriban o alteren JSON en la cola: el procesador valida el formato, pero no realiza otra autenticación AD ni una firma digital. Los archivos pendientes son entrada confiada únicamente cuando sus permisos reservan su generación al servicio web autorizado y su gestión al procesador.

Referencias primarias: [configuración de Windows Authentication en IIS](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/security/authentication/windowsauthentication/), [variables de servidor de IIS](https://github.com/MicrosoftDocs/iis-docs/blob/main/iis/web-dev-reference/server-variables.md), [variables de servidor en PHP](https://www.php.net/manual/en/reserved.variables.server.php).

## Comportamiento de la aplicación

El adaptador obtiene `AUTH_USER` **en cada petición**, incluida la petición POST que publica el JSON. No usa `USERNAME`, `USER`, la cuenta del pool, la cuenta del `.bat`, datos de sesión como fuente de identidad, campos POST, cookies de autor, `PHP_AUTH_USER` ni cabeceras `HTTP_*`. Los controles de sesión siguen sirviendo solo para CSRF y mensajes.

El usuario se muestra como texto y nunca como campo de formulario. En el modo corporativo, el JSON se genera en el servidor, por ejemplo:

```json
{
  "version": 1,
  "id": "req_0123456789abcdef0123456789abcdef",
  "tipo": "update_proveedor",
  "usuario": "DOMINIO\\persona",
  "fecha": "2026-10-08T12:00:00Z",
  "datos": {"id": 1, "nombre": "Proveedor de prueba"}
}
```

No se fija `KENJI` ni ninguna cuenta real. Se conserva el dominio/UPN que autentica IIS, sin reducirlo a un nombre ambiguo. El procesador conserva `usuario` en `solicitudes.usuario`, dentro de `payload` y en el JSON archivado. Deduplicación y transacción incluyen también ese contenido: reutilizar un ID con otro usuario produce un error.

Si falta o es inconsistente la identidad y la excepción de pruebas está desactivada, el formulario devuelve HTTP 403, explica la configuración necesaria y deshabilita los controles. Un POST directo tampoco crea cola, temporal ni JSON. **El servidor local `serve-test.bat` no autentica Windows y sus envíos quedan bloqueados por defecto**, incluso si se define `INVOICE_CORPORATE_AUTH`; la excepción explícita descrita al inicio permite generar como `anonymous`. Abrir PHP como archivo tampoco identifica al visitante ni lo ejecuta.

La BD de prueba ya existente se migra agregando la columna `usuario` sin perder filas. Los registros antiguos conservan NULL porque su autor no fue verificado. Los JSON pendientes anteriores sin autor válido pasan a errores; no se puede atribuirlos retrospectivamente a la cuenta de PHP o a una persona arbitraria. `test_share` permanece sin cambios como referencia.

## Validación realizada y aceptación pendiente

Ejecutar la suite:

```powershell
.\php-8.5.11\php.exe -n -d extension_dir=./php-8.5.11/ext -d extension=pdo_sqlite tests/queue-test.php
```

Se verificaron dos autores distintos (`EXAMPLE\autor-a` y `EXAMPLE\autor-b`) mediante **fixtures de servidor**, dos procesos HTTP locales y sesiones independientes. En ambos casos se verificó el JSON, el procesamiento real por `.bat`, la columna SQLite y el archivo archivado. Los fixtures se fijan en el entorno del servidor de pruebas, no mediante un usuario recibido por HTTP. `tests/identity-router.php` es exclusivamente un doble de prueba; el enrutador normal no lo expone ni incluye. No desplegar la carpeta `tests` en el sitio público.

También pasaron rechazo de cuentas del proceso, usuario simple sin dominio, tipo Basic, servidor PHP local, identidad ausente y variables de servidor contradictorias con la excepción de pruebas desactivada; POST/cabeceras falsificados, migración sin autor inventado y JSON sin usuario. Con la excepción activada se verificaron envío anónimo, warning, prioridad del autor verificado, persistencia de `anonymous` y reejecución sin duplicados. Las pruebas originales de consistencia y recuperación siguen pasando.

**Esto no verifica dos identidades corporativas reales ni la autenticación de IIS/AD.** No se dispone de esas cuentas o del servicio en este entorno. Para completar la aceptación, TI debe realizar esta prueba en el sitio autorizado:

1. Dos personas, desde sus propias sesiones/equipos de dominio, abren el sitio y confirman que el nombre mostrado coincide con su cuenta corporativa completa. No usar solo dos pestañas del mismo navegador, que pueden compartir identidad integrada.
2. Cada una envía una solicitud con ID de proveedor y nombre de prueba diferentes; anotar ambos identificadores `req_...`.
3. En cada JSON pendiente, comprobar que `usuario` corresponde a su autor y no a la cuenta del pool ni a la de la tarea programada.
4. Ejecutar el `.bat` en el equipo designado y consultar localmente:

   ```sql
   SELECT id, proveedor_id, nombre, usuario, confirmado_en
   FROM solicitudes
   WHERE id IN ('req_de_persona_a', 'req_de_persona_b');
   ```

5. Comparar con los dos JSON archivados. Reejecutar el `.bat` y comprobar que siguen existiendo exactamente las dos filas, con sus autores originales.
6. Confirmar que una petición no autenticada no llega al formulario o queda bloqueada, y que campos/cabeceras de usuario no cambian el autor autenticado.

Guardar la evidencia de IIS y de ambos identificadores en el procedimiento de TI. No marcar esta aceptación como completada con los fixtures locales. No se almacenan contraseñas ni se añade un formulario de login propio.
