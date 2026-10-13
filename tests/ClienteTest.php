<?php

declare(strict_types=1);

namespace Egobytes\Rnp\Tests;

use Egobytes\Rnp\Cliente;
use Egobytes\Rnp\Consulta;
use Egobytes\Rnp\ConsultaDekra;
use Egobytes\Rnp\EstadoRegistral;
use Egobytes\Rnp\RnpException;
use Egobytes\Rnp\Saldo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Lo que el cliente promete, sin tocar la red.
 *
 * Se prueba la clasificación de errores y la lectura de la respuesta, que es
 * donde vive el criterio del paquete. Lo demás es una llamada HTTP.
 */
class ClienteTest extends TestCase
{
    // ── Validación antes de gastar una llamada ──

    public function test_exige_una_clave(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cliente('');
    }

    public function test_rechaza_una_placa_con_guiones_sin_llamar_al_api(): void
    {
        // Cortar aquí evita gastar una consulta para que el API conteste que
        // no existe.
        $this->expectException(InvalidArgumentException::class);

        (new Cliente('rnp_x'))->porPlaca('BJK-123');
    }

    public function test_rechaza_una_clase_inventada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Cliente('rnp_x'))->porPlaca('BJK123', 'taxi');
    }

    public function test_rechaza_una_placa_de_dekra_con_guiones_sin_llamar_al_api(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Cliente('rnp_x'))->dekraPorPlaca('BJK-123');
    }

    public function test_acepta_la_moto_escrita_como_en_la_placa_o_como_en_el_contrato(): void
    {
        $this->assertSame('MOT', Cliente::normalizarClase('MOT'));
        $this->assertSame('MOT', Cliente::normalizarClase('moto'));
        $this->assertSame('MOT', Cliente::normalizarClase('M'));
        $this->assertSame('CL', Cliente::normalizarClase('cl'));
        $this->assertSame('particular', Cliente::normalizarClase('particular'));
    }

    // ── Los tres estados registrales ──

    public function test_posee_true_es_confirmado(): void
    {
        $estado = EstadoRegistral::desdeArray(['posee' => true, 'items' => []]);

        $this->assertTrue($estado->confirmado());
        $this->assertFalse($estado->descartado());
        $this->assertFalse($estado->indeterminado());
    }

    public function test_posee_false_es_descartado(): void
    {
        $estado = EstadoRegistral::desdeArray(['posee' => false, 'items' => []]);

        $this->assertTrue($estado->descartado());
        $this->assertFalse($estado->confirmado());
    }

    public function test_sin_posee_queda_indeterminado_y_no_se_confunde_con_un_no(): void
    {
        // Es el fallo que reportó un cliente real: null significaba a la vez
        // "no tiene" y "no se pudo determinar".
        $estado = EstadoRegistral::desdeArray(['items' => []]);

        $this->assertTrue($estado->indeterminado());
        $this->assertFalse($estado->descartado());
        $this->assertFalse($estado->confirmado());
    }

    public function test_una_seccion_ausente_tambien_queda_indeterminada(): void
    {
        $this->assertTrue(EstadoRegistral::desdeArray(null)->indeterminado());
    }

    // ── Leer la respuesta ──

    private function consultaDePrueba(): Consulta
    {
        return Consulta::desdeArray([
            'datos' => [
                'vehiculo'     => ['marca' => 'TOYOTA', 'estilo' => 'COROLLA', 'color' => null],
                'motor'        => ['cilindrada' => '1800 C.C'],
                'propietarios' => [['nombre' => 'PERSONA DE PRUEBA']],
                'gravamenes'   => ['posee' => true, 'items' => [['tipo' => 'PRENDA']]],
            ],
            'completitud' => 'completa',
            'cobrada'     => true,
            'saldo'       => ['total' => 248, 'plan' => 248, 'creditos' => 0],
            'request_id'  => '01J8XYZ',
        ]);
    }

    public function test_lee_los_campos_del_vehiculo(): void
    {
        $consulta = $this->consultaDePrueba();

        $this->assertSame('TOYOTA', $consulta->campo('marca'));
        $this->assertSame('1800 C.C', $consulta->motor()['cilindrada']);
        $this->assertCount(1, $consulta->propietarios());
        $this->assertTrue($consulta->gravamenes()->confirmado());
    }

    public function test_un_campo_que_el_registro_no_publica_devuelve_null(): void
    {
        // Y no una cadena vacía, que se confundiría con un dato real.
        $this->assertNull($this->consultaDePrueba()->campo('color'));
        $this->assertNull($this->consultaDePrueba()->campo('no_existe'));
    }

    public function test_lee_lo_que_el_api_agrega_al_vehiculo(): void
    {
        $consulta = Consulta::desdeArray([
            'datos' => [
                'vehiculo' => ['marca' => 'TOYOTA'],
                'vencimiento_dekra' => [
                    'fecha' => '2028-02-29', 'placa' => 'BJK123', 'vigente' => true,
                ],
                'inspeccion_tecnica' => [
                    'entidad' => 'Dekra', 'digito' => 3, 'mes' => 3, 'mes_nombre' => 'marzo',
                ],
                'estimaciones' => [
                    'marchamo' => ['total' => 150000, 'referencial' => true, 'moneda' => 'CRC'],
                    'traspaso' => null,
                ],
                'origen' => [
                    'pais_fabricacion' => 'JAPAN', 'fabricante' => 'TOYOTA', 'anio_modelo' => 2019,
                ],
            ],
            'completitud' => 'completa',
            'cobrada'     => true,
        ]);

        $this->assertTrue($consulta->vencimientoDekra()['vigente']);
        $this->assertSame('BJK123', $consulta->vencimientoDekra()['placa']);
        $this->assertSame('marzo', $consulta->inspeccionTecnica()['mes_nombre']);
        $this->assertSame(150000, $consulta->estimaciones()['marchamo']['total']);
        $this->assertNull($consulta->estimaciones()['traspaso']);
        $this->assertSame('JAPAN', $consulta->origen()['pais_fabricacion']);
    }

    public function test_un_dato_agregado_que_no_vino_queda_en_null(): void
    {
        $consulta = Consulta::desdeArray([
            'datos' => [
                'vencimiento_dekra'  => null,
                'inspeccion_tecnica' => null,
                'origen'             => null,
            ],
            'completitud' => 'completa',
            'cobrada'     => true,
        ]);

        $this->assertNull($consulta->vencimientoDekra());
        $this->assertNull($consulta->inspeccionTecnica());
        $this->assertNull($consulta->origen());
        $this->assertNull($consulta->estimaciones()['marchamo']);
        $this->assertNull($consulta->estimaciones()['traspaso']);
    }

    public function test_la_consulta_de_dekra_no_se_cobra_y_puede_venir_sin_fecha(): void
    {
        $conFecha = ConsultaDekra::desdeArray([
            'datos' => ['fecha' => '2028-02-29', 'placa' => 'BJK123', 'vigente' => true],
            'cobrada'       => false,
            'consultado_en' => '2026-10-13T08:00:00-06:00',
            'request_id'    => '01JEXAMPLE',
        ]);

        $this->assertFalse($conFecha->cobrada);
        $this->assertSame('2028-02-29', $conFecha->datos['fecha']);
        $this->assertTrue($conFecha->vigente());
        $this->assertSame('01JEXAMPLE', $conFecha->requestId);

        $sinFecha = ConsultaDekra::desdeArray(['datos' => null, 'cobrada' => false]);

        $this->assertNull($sinFecha->datos);
        $this->assertNull($sinFecha->vigente());
        $this->assertFalse($sinFecha->cobrada);
    }

    public function test_una_fecha_de_dekra_sin_vigente_no_se_lee_como_vencida(): void
    {
        $vieja = ConsultaDekra::desdeArray([
            'datos'   => ['fecha' => '2028-02-29', 'placa' => 'BJK123'],
            'cobrada' => false,
        ]);

        $this->assertNull($vieja->vigente());
    }

    public function test_una_respuesta_parcial_se_reconoce(): void
    {
        $parcial = Consulta::desdeArray([
            'datos' => [], 'completitud' => 'parcial',
            'secciones_ausentes' => ['propietarios'], 'cobrada' => false,
        ]);

        $this->assertFalse($parcial->esCompleta());
        $this->assertFalse($parcial->cobrada);
        $this->assertSame(['propietarios'], $parcial->seccionesAusentes);
    }

    // ── Clasificación de errores ──

    private function error(string $codigo, int $http, array $extra = []): RnpException
    {
        return RnpException::desdeRespuesta([
            'error'   => array_merge(['code' => $codigo, 'message' => 'da igual'], $extra),
            'cobrada' => $codigo === 'vehiculo_no_encontrado',
        ], $http);
    }

    public function test_reconoce_que_no_hay_vehiculo(): void
    {
        $e = $this->error('vehiculo_no_encontrado', 404);

        $this->assertTrue($e->noEncontrado());
        $this->assertFalse($e->esReintentable());
        $this->assertTrue($e->cobrada, 'Es el único error que descuenta: el Registro sí respondió.');
    }

    public function test_reconoce_la_falta_de_saldo(): void
    {
        foreach (['saldo_agotado', 'prueba_finalizada'] as $codigo) {
            $e = $this->error($codigo, 402, ['recarga_url' => 'https://rnp.egobytes.com/portal/recargar']);

            $this->assertTrue($e->faltaSaldo());
            $this->assertFalse($e->esReintentable(), 'Reintentar sin saldo solo gasta tiempo.');
            $this->assertSame('https://rnp.egobytes.com/portal/recargar', $e->recargaUrl);
        }
    }

    public function test_reconoce_un_problema_de_credencial(): void
    {
        foreach (['credencial_invalida', 'credencial_revocada', 'cuenta_suspendida'] as $codigo) {
            $e = $this->error($codigo, 401);

            $this->assertTrue($e->problemaDeCredencial());
            $this->assertFalse($e->esReintentable());
        }
    }

    public function test_reconoce_lo_que_vale_la_pena_reintentar(): void
    {
        foreach (RnpException::REINTENTABLES as $codigo) {
            $this->assertTrue($this->error($codigo, 503)->esReintentable(), $codigo);
        }
    }

    public function test_el_limite_por_minuto_dice_cuanto_esperar(): void
    {
        $e = $this->error('limite_excedido', 429, ['reintentar_en' => 12]);

        $this->assertSame(12, $e->reintentarEn);
        $this->assertTrue($e->esReintentable());
    }

    public function test_una_caida_de_red_tambien_es_reintentable(): void
    {
        $e = RnpException::sinRespuesta('Could not resolve host');

        $this->assertSame('sin_conexion', $e->codigo);
        $this->assertTrue($e->esReintentable());
        $this->assertFalse($e->cobrada);
    }

    public function test_un_codigo_desconocido_no_revienta_ni_se_reintenta(): void
    {
        // El API puede añadir códigos; un cliente viejo tiene que sobrevivirlo.
        $e = $this->error('algo_que_no_existia', 500);

        $this->assertFalse($e->esReintentable());
        $this->assertFalse($e->noEncontrado());
        $this->assertFalse($e->faltaSaldo());
    }

    // ── Saldo ──

    public function test_avisa_cuando_el_saldo_se_esta_acabando(): void
    {
        $this->assertTrue(Saldo::desdeArray(['total' => 3])->seEstaAcabando());
        $this->assertFalse(Saldo::desdeArray(['total' => 500])->seEstaAcabando());
    }

    public function test_una_cuenta_exenta_nunca_se_esta_acabando(): void
    {
        $this->assertFalse(Saldo::desdeArray(['exento' => true])->seEstaAcabando());
    }
}
