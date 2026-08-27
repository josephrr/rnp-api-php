# rnp-api · cliente PHP

Cliente del API de consulta vehicular del **Registro Nacional de Costa Rica**.
Consulta por placa o por VIN y devuelve características, motor, propietarios y
situación registral.

Sin dependencias: solo cURL, que ya viene con PHP.

```bash
composer require egobytes/rnp-api
```

Necesita PHP 8.2 o superior y una clave, que se genera en el portal, sección
Credenciales.

## Empezar

```php
use Egobytes\Rnp\Cliente;

$rnp = new Cliente('rnp_su_clave');

$consulta = $rnp->porPlaca('BJK123');

echo $consulta->campo('marca');      // TOYOTA
echo $consulta->campo('estilo');     // COROLLA
echo $consulta->saldo->total;        // 248
```

O tomando la clave del entorno, que es donde debería estar:

```php
$rnp = Cliente::desdeEntorno();      // lee RNP_API_KEY
```

## Carga liviana

El `CL` es la **clase**, no parte de la placa:

```php
$rnp->porPlaca('272490', 'CL');      // correcto
$rnp->porPlaca('CL272490');          // no encuentra nada
```

## Por VIN

```php
$consulta = $rnp->porVin('8AJHA8CD704309461');
```

## Gravámenes y anotaciones

`posee` tiene **tres** estados, no dos. `null` significa que el Registro no lo
dijo, y tratarlo como un «no» es afirmar que un vehículo está libre cuando en
realidad no se sabe. Por eso no existe un método `posee(): bool`:

```php
$gravamenes = $consulta->gravamenes();

if ($gravamenes->confirmado()) {
    // El Registro afirma que sí tiene
    foreach ($gravamenes->items as $item) {
        echo $item['tipo'] . ' · ' . $item['fecha'];
    }
} elseif ($gravamenes->descartado()) {
    // El Registro afirma que no tiene
} else {
    // El Registro no lo dijo: no se puede concluir nada
}
```

Lo mismo con `anotaciones()`, `infracciones()` y `levantamientos()`.

## Errores

Todos los fallos del API lanzan `RnpException`. Ramifique por `codigo`, nunca
por el mensaje: el texto puede cambiar sin aviso y el código no.

```php
use Egobytes\Rnp\RnpException;

try {
    $consulta = $rnp->porPlaca('BJK123');
} catch (RnpException $e) {
    if ($e->noEncontrado()) {
        // 404: no hay vehículo con esa placa. Es el único error que descuenta.
    } elseif ($e->faltaSaldo()) {
        // 402: hay que recargar. $e->recargaUrl tiene el enlace.
    } elseif ($e->problemaDeCredencial()) {
        // 401/403: la clave no sirve. Reintentar no ayuda.
    } elseif ($e->esReintentable()) {
        // El Registro está caído o saturado. El cliente ya reintentó solo.
    }

    // Cite este identificador al reportar un problema.
    error_log($e->requestId);
}
```

| Método | Cuándo |
|---|---|
| `noEncontrado()` | `vehiculo_no_encontrado` — el Registro respondió y no hay nada inscrito |
| `faltaSaldo()` | `saldo_agotado`, `prueba_finalizada` |
| `problemaDeCredencial()` | `credencial_invalida`, `credencial_revocada`, `cuenta_suspendida` |
| `esReintentable()` | `limite_excedido`, `origen_no_disponible`, `sin_capacidad`, `tiempo_agotado` |

## Reintentos

El cliente reintenta por su cuenta lo que puede cambiar de resultado: el
Registro caído, saturado o lento. Un 402 o un 404 dan lo mismo diez veces, así
que no se reintentan.

Cuando el API indica cuántos segundos esperar —el límite por minuto— se respeta
ese número; si no, retrocede exponencialmente: 1 s, 2 s, 4 s.

```php
$rnp = new Cliente('rnp_su_clave', reintentos: 4);   // más insistente
$rnp = new Cliente('rnp_su_clave', reintentos: 0);   // sin reintentos
```

## Cómo se cobra

Se descuenta **una consulta por cada respuesta completa**. Si el Registro no
responde, responde a medias o rechaza la petición, la consulta no se cobra, y
la respuesta lo dice:

```php
$consulta->cobrada;        // false cuando no se descontó
$consulta->esCompleta();   // false si el Registro no entregó todas las secciones
$consulta->saldo->total;   // lo que queda, ya con este cobro cerrado
```

Una respuesta parcial trae datos buenos pero incompletos: sirve para mostrar,
no para concluir que un vehículo está libre de gravámenes.

## Vehículos de prueba

Estas placas devuelven datos ficticios, no tocan el Registro y no consumen
saldo. Detrás de una placa real hay una persona con nombre y cédula: programe
contra estas.

| Placa | Qué devuelve |
|---|---|
| `DEMO001` | Vehículo con dos gravámenes activos |
| `DEMO002` | Vehículo con anotaciones y un levantamiento |
| `DEMO003` | Vehículo sin gravámenes ni anotaciones |
| `DEMO404` | Placa inexistente: lanza `vehiculo_no_encontrado` |

## Guarde en caché lo que consulte

Los datos de un vehículo casi no cambian de un día para otro. Guardar cada
respuesta al menos 24 horas de su lado le baja la factura y le quita
dependencia de que el Registro esté disponible en ese instante.

## Advertencia

Este servicio presenta información publicada por el Registro Nacional. No la
genera, no la valida y no la certifica: **no sustituye una certificación
registral**. Para cualquier trámite legal se requiere el documento oficial.

---

Documentación completa: <https://rnp.egobytes.com/docs> ·
Soporte: <soporte@egobytes.com>
