{{--
  La factura d'un local comercial. Les mides i les posicions són les de les factures
  que s'han emès a mà fins ara (A4, cos de 7pt, taula de 73 a 513pt): el paper ha de
  quedar igual que el dels anys anteriors, que és el que el client ja té arxivat.
--}}
@php
    $eur = fn ($v) => number_format($v, 2, ',', '.') . ' €';
    $pct = fn ($v) => number_format($v, 2, ',', '.') . ' %';
@endphp
<!DOCTYPE html>
<html lang="ca">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, sans-serif; font-size: 7pt; color: #000; }
        .abs { position: absolute; }
        .b { font-weight: bold; }
        .gris { color: #5f5f5f; }
        table.linies { width: 440.23pt; border-collapse: collapse; table-layout: fixed; }
        table.linies td { font-size: 7pt; vertical-align: middle; }
        .c1 { width: 158.27pt; padding-left: 2.8pt; }
        .c2 { width: 118.72pt; padding-left: 2.8pt; }
        .c3 { width: 85.72pt; }
        .c4 { width: 77.51pt; text-align: right; padding-right: 5pt; }
        .capcalera td { background-color: #d5d5d5; height: 13.46pt; border-bottom: 0.5pt solid #808080; }
        .dreta { text-align: right; padding-right: 2.8pt; }
        .tancament td { border-bottom: 1pt solid #000; }
    </style>
</head>
<body>

<div class="abs" style="left: 72pt; top: 55pt; width: 441pt; border-top: 1.5pt solid #000;"></div>
<div class="abs" style="left: 70.5pt; top: 98pt; width: 136pt; height: 133pt; border: 0.5pt solid #c8c8c8;"></div>
<div class="abs" style="left: 384.3pt; top: 139.3pt; width: 126.7pt; height: 92pt; border: 0.5pt solid #c8c8c8;"></div>

{{-- Arrendadora --}}
<div class="abs b" style="left: 72pt; top: 63pt; font-size: 9.1pt;">{{ $emissor['nom'] }}</div>

{{-- Capçalera esquerra: número, data i dades de l'arrendadora --}}
<div class="abs" style="left: 82pt; top: 105pt; font-size: 14pt; color: #808080;">FACTURA</div>
<div class="abs b" style="left: 72pt; top: 142.6pt; width: 36pt; text-align: right;">Número:</div>
<div class="abs b" style="left: 111.8pt; top: 139.8pt; font-size: 9.8pt;">{{ $numero }}</div>
<div class="abs" style="left: 72pt; top: 156pt; width: 36pt; text-align: right;">Data:</div>
<div class="abs" style="left: 113.2pt; top: 156pt;">{{ $data }}</div>
<div class="abs" style="left: 72pt; top: 182.8pt; width: 36pt; text-align: right;">NIF:</div>
<div class="abs" style="left: 113.2pt; top: 182.8pt;">{{ $emissor['nif'] }}</div>
<div class="abs" style="left: 72pt; top: 196.1pt; width: 36pt; text-align: right;">Adreça:</div>
@foreach ($emissor['adreca'] as $i => $linia)
    <div class="abs" style="left: 113.2pt; top: {{ 196.1 + $i * 9.2 }}pt;">{{ $linia }}</div>
@endforeach
@if ($emissor['telefon'])
    <div class="abs" style="left: 113.2pt; top: {{ 196.1 + max(count($emissor['adreca']), 1) * 9.2 }}pt;">Tel. {{ $emissor['telefon'] }}</div>
@endif

{{-- Client --}}
<div class="abs b" style="left: 391pt; top: 148pt;">Client:</div>
<div class="abs" style="left: 391pt; top: 168.5pt;">{{ $client['nom'] }}</div>
@if ($client['adreca'])
    <div class="abs" style="left: 391pt; top: 179pt;">{{ $client['adreca'] }}</div>
@endif
@if ($client['cp_poblacio'])
    <div class="abs" style="left: 391pt; top: 189.5pt;">{{ $client['cp_poblacio'] }}</div>
@endif
@if ($client['nif'])
    <div class="abs" style="left: 391pt; top: 208pt;">NIF: {{ $client['nif'] }}</div>
@endif

{{-- Taula. Va amb posicions absolutes i no amb <table>: dompdf no respecta
     table-layout:fixed i repartia les columnes pel contingut. --}}
@php
    // Les alçades de fila són les de les factures anteriors. La primera és més alta
    // perquè el concepte del lloguer hi ocupa dues línies quan és llarg.
    $y = 285.7;                 // vora de dalt de la capçalera
    $alcadaCapcalera = 13.46;
    $files = [];
    foreach ($linies as $i => $linia) {
        $files[] = ['tipus' => 'linia', 'alt' => $i === 0 ? 21.67 : 13.8] + $linia;
    }
    $files[] = ['tipus' => 'buida', 'alt' => 13.79, 'linia_sota' => true];
    $files[] = ['tipus' => 'total', 'alt' => 13.8, 'etiqueta' => 'Base imponible', 'pct' => null, 'import' => $base];
    $files[] = ['tipus' => 'total', 'alt' => 13.53, 'etiqueta' => 'IVA', 'pct' => $iva_perc, 'import' => $iva];
    if ($irpf_perc || $irpf) {
        $files[] = ['tipus' => 'total', 'alt' => 14.06, 'etiqueta' => 'Retenció IRPF Lloguers locals comercials', 'pct' => -$irpf_perc, 'import' => -$irpf];
    }
    $files[] = ['tipus' => 'buida', 'alt' => 13.88, 'linia_sota' => true];
    $files[] = ['tipus' => 'suma', 'alt' => 13.88];
@endphp

{{-- Capçalera de la taula --}}
<div class="abs" style="left: 73.05pt; top: {{ $y }}pt; width: 440.23pt; height: {{ $alcadaCapcalera }}pt; background-color: #d5d5d5; border-bottom: 0.5pt solid #808080;"></div>
<div class="abs b" style="left: 75.85pt; top: {{ $y + 3.4 }}pt;">Concepte</div>
<div class="abs b" style="left: 435.77pt; top: {{ $y + 3.4 }}pt; width: 72.5pt; text-align: right;">Imports</div>
@php $y += $alcadaCapcalera; @endphp

@foreach ($files as $fila)
    @php $centrat = $y + ($fila['alt'] - 8) / 2; @endphp

    @if ($fila['tipus'] === 'linia')
        <div class="abs" style="left: 75.85pt; top: {{ $fila['alt'] > 14 ? $y + 3.5 : $centrat }}pt; width: 155pt;">{{ $fila['concepte'] }}</div>
        @if ($fila['qualificador'])
            <div class="abs" style="left: 234.1pt; top: {{ $fila['alt'] > 14 ? $y + 3.5 : $centrat }}pt;">({{ $fila['qualificador'] }})</div>
        @endif
        <div class="abs" style="left: 435.77pt; top: {{ $fila['alt'] > 14 ? $y + 3.5 : $centrat }}pt; width: 72.5pt; text-align: right;">{{ $eur($fila['import']) }}</div>
    @elseif ($fila['tipus'] === 'total')
        <div class="abs" style="left: 150pt; top: {{ $centrat }}pt; width: 197.2pt; text-align: right;">{{ $fila['etiqueta'] }}</div>
        @if ($fila['pct'] !== null)
            <div class="abs" style="left: 350.05pt; top: {{ $centrat }}pt; width: 82.9pt; text-align: right;">{{ $pct($fila['pct']) }}</div>
        @endif
        <div class="abs" style="left: 435.77pt; top: {{ $centrat }}pt; width: 72.5pt; text-align: right;">{{ $eur($fila['import']) }}</div>
    @elseif ($fila['tipus'] === 'suma')
        <div class="abs" style="left: 350.05pt; top: {{ $y }}pt; width: 162.2pt; height: {{ $fila['alt'] - 1 }}pt; border-left: 1pt solid #000; border-right: 1pt solid #000; border-bottom: 1pt solid #000;"></div>
        <div class="abs b" style="left: 361.1pt; top: {{ $centrat - 0.4 }}pt; font-size: 7.7pt;">TOTAL LLOGUER</div>
        <div class="abs b" style="left: 435.77pt; top: {{ $centrat - 0.4 }}pt; width: 72.5pt; text-align: right; font-size: 7.7pt;">{{ $eur($total) }}</div>
    @endif

    @php $y += $fila['alt']; @endphp
    @if (!empty($fila['linia_sota']))
        <div class="abs" style="left: 73.05pt; top: {{ $y }}pt; width: 440.23pt; border-top: 1pt solid #000;"></div>
    @endif
@endforeach

{{-- Peu: condicions de pagament --}}
<div class="abs" style="left: 72pt; top: 631.4pt; width: 441pt; border-top: 1.5pt solid #000;"></div>
<div class="abs gris" style="left: 75.15pt; top: 641.8pt;">Condicions pagament</div>
@foreach ($condicions as $i => $linia)
    @if ($linia['etiqueta'])
        <div class="abs gris" style="left: 75.15pt; top: {{ 661.2 + $i * 9.2 }}pt; font-size: 6.3pt;">{{ $linia['etiqueta'] }}</div>
    @endif
    @if ($linia['valor'])
        <div class="abs gris" style="left: 160.78pt; top: {{ 661.2 + $i * 9.2 }}pt; font-size: 6.3pt;">{{ $linia['valor'] }}</div>
    @endif
@endforeach
<div class="abs" style="left: 311.5pt; top: 720pt; font-size: 5.6pt;">El pagament d'aquesta factura queda justificat amb l'apunt bancari corresponent.</div>
<div class="abs" style="left: 0; top: 809.7pt; width: 595.28pt; text-align: center;">1</div>

</body>
</html>
