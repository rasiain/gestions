<?php

namespace Tests\Feature;

use App\Support\LectorGpx;
use Tests\TestCase;

/** Un GPX queda en la mateixa forma que una sessió de FIT. */
class LectorGpxTest extends TestCase
{
    public function test_llegeix_les_posicions_i_calcula_la_distancia_i_la_velocitat(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'gpx') . '.gpx';
        file_put_contents($ruta, <<<'GPX'
            <?xml version="1.0" encoding="UTF-8"?>
            <gpx xmlns="http://www.topografix.com/GPX/1/1" creator="OruxMaps" version="1.1">
              <trk><name>Prova</name><trkseg>
                <trkpt lat="41.9775757" lon="2.8199437"><ele>160.54</ele><time>2016-11-20T07:14:47Z</time></trkpt>
                <trkpt lat="41.9784757" lon="2.8199437"><ele>165.00</ele><time>2016-11-20T07:15:07Z</time></trkpt>
                <trkpt lat="41.9793757" lon="2.8199437"><time>2016-11-20T07:15:27Z</time></trkpt>
              </trkseg></trk>
            </gpx>
            GPX);

        $s = LectorGpx::llegeix($ruta);

        $this->assertSame(strtotime('2016-11-20T07:14:47Z'), $s['inici']);
        $this->assertSame('gpx', $s['sessio']['format']);
        $this->assertTrue($s['sessio']['calculat']);
        $this->assertCount(3, $s['punts']);
        // Semicercles i cm, com el FIT
        $this->assertSame((int) round(41.9775757 * 2 ** 31 / 180), $s['punts'][0][1]);
        $this->assertSame(16054, $s['punts'][0][3]);
        $this->assertNull($s['punts'][2][3]);
        // 0,0009° de latitud són ~100 m; en 20 s, ~5 m/s
        $this->assertEqualsWithDelta(200.1, $s['sessio']['totalDistance'], 0.5);
        $this->assertSame([20, 40], [$s['punts'][1][0], $s['punts'][2][0]]);
        $this->assertEqualsWithDelta(5003, $s['punts'][1][5], 5);
        $this->assertEqualsWithDelta(41.9775757, $s['rectangle']['lat_min'], 1e-9);
        $this->assertNotEmpty($s['simplificat']);
    }
}
