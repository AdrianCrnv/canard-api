<?php

namespace App\Models;

use Illuminate\Support\Facades\Log;

class Report {

    protected function downloadStaticMap(
        float $centerLat,
        float $centerLng,
        array $markers,
        string $tempFolder,
        string $apiKey = '',
        int $width = 500,
        int $height = 300,
        int $zoom = 16,
        int $markerSize = 12,
        int $outlinePadding = 4,
        float $rotationDeg = 0
    ): ?string {
        try {
            Log::info("[MAP] downloadStaticMap llamado: center={$centerLat},{$centerLng} markers=" . count($markers) . " folder={$tempFolder} rotation={$rotationDeg}");

            $tileSize = 256;
            $n        = pow(2, $zoom);

            $rad = deg2rad(abs($rotationDeg));
            $srcWidth  = $rotationDeg != 0
                ? (int) ceil($width  * abs(cos($rad)) + $height * abs(sin($rad))) + $tileSize
                : $width;
            $srcHeight = $rotationDeg != 0
                ? (int) ceil($width  * abs(sin($rad)) + $height * abs(cos($rad))) + $tileSize
                : $height;

            $latRad      = deg2rad($centerLat);
            $centerTileXf = ($centerLng + 180) / 360 * $n;
            $centerTileYf = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n;

            $centerTileX = (int) floor($centerTileXf);
            $centerTileY = (int) floor($centerTileYf);

            $halfW = (int) ceil(($srcWidth  / 2 + $tileSize) / $tileSize);
            $halfH = (int) ceil(($srcHeight / 2 + $tileSize) / $tileSize);

            $canvas = imagecreatetruecolor($srcWidth, $srcHeight);
            $bgColor = imagecolorallocate($canvas, 240, 240, 240);
            imagefill($canvas, 0, 0, $bgColor);

            $context = stream_context_create([
                'http' => [
                    'timeout' => 8,
                    'header'  => "User-Agent: DronePlatform/1.0\r\n",
                ]
            ]);

            for ($tx = $centerTileX - $halfW; $tx <= $centerTileX + $halfW; $tx++) {
                for ($ty = $centerTileY - $halfH; $ty <= $centerTileY + $halfH; $ty++) {
                    if ($ty < 0 || $ty >= $n) continue;
                    $txClamped = (($tx % $n) + $n) % $n;

                    $canvasX = (int) round($srcWidth  / 2 - ($centerTileXf - $tx) * $tileSize);
                    $canvasY = (int) round($srcHeight / 2 - ($centerTileYf - $ty) * $tileSize);

                    if ($canvasX >= $srcWidth || $canvasY >= $srcHeight ||
                        $canvasX + $tileSize <= 0 || $canvasY + $tileSize <= 0) {
                        continue;
                    }

                    $tileUrl = "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{$zoom}/{$ty}/{$txClamped}";
                    $tileContent = @file_get_contents($tileUrl, false, $context);
                    if ($tileContent === false) {
                        Log::warning("[MAP] Fallo descarga tile: {$tileUrl}");
                        continue;
                    }

                    $tileSrc = @imagecreatefromstring($tileContent);
                    if (!$tileSrc) {
                        Log::warning("[MAP] No se pudo crear imagen del tile: {$tileUrl}");
                        continue;
                    }

                    imagecopy($canvas, $tileSrc, $canvasX, $canvasY, 0, 0, $tileSize, $tileSize);
                    imagedestroy($tileSrc);
                }
            }

            imagealphablending($canvas, true);
            foreach ($markers as $m) {
                $mTileXf = ($m['lng'] + 180) / 360 * $n;
                $mTileYf = (1 - log(tan(deg2rad($m['lat'])) + 1 / cos(deg2rad($m['lat']))) / M_PI) / 2 * $n;

                $mx = (int) round($srcWidth  / 2 + ($mTileXf - $centerTileXf) * $tileSize);
                $my = (int) round($srcHeight / 2 + ($mTileYf - $centerTileYf) * $tileSize);

                if ($mx < 0 || $mx >= $srcWidth || $my < 0 || $my >= $srcHeight) continue;

                $color = $m['color'] ?? 'blue';
                if ($color === 'red') {
                    $fill = imagecolorallocate($canvas, 220, 30, 30);
                } elseif ($color === 'green') {
                    $fill = imagecolorallocate($canvas, 0, 200, 0);
                } else {
                    $fill = imagecolorallocate($canvas, 30, 100, 220);
                }
                $outline = imagecolorallocate($canvas, 255, 255, 255);

                if ($color === 'red' && isset($m['bearing']) && $m['bearing'] !== null) {
                    $coneLen   = 30;
                    $coneHalfW = 12;
                    $rad = deg2rad($m['bearing']);

                    $relPts = [[-$coneHalfW, -$coneLen], [$coneHalfW, -$coneLen]];

                    $polygon = [$mx, $my];
                    foreach ($relPts as [$rx, $ry]) {
                        $polygon[] = (int) round($mx + $rx * cos($rad) - $ry * sin($rad));
                        $polygon[] = (int) round($my + $rx * sin($rad) + $ry * cos($rad));
                    }

                    $coneColor = imagecolorallocatealpha($canvas, 255, 200, 50, 50);
                    imagefilledpolygon($canvas, $polygon, $coneColor);
                }

                imagefilledellipse($canvas, $mx, $my, $markerSize + $outlinePadding, $markerSize + $outlinePadding, $outline);
                imagefilledellipse($canvas, $mx, $my, $markerSize, $markerSize, $fill);
            }

            if ($rotationDeg != 0) {
                $bgFill = imagecolorallocate($canvas, 240, 240, 240);
                $rotated = imagerotate($canvas, $rotationDeg, $bgFill);
                imagedestroy($canvas);

                $rW = imagesx($rotated);
                $rH = imagesy($rotated);
                $cropX = (int) max(0, ($rW - $width)  / 2);
                $cropY = (int) max(0, ($rH - $height) / 2);

                $final = imagecreatetruecolor($width, $height);
                imagecopy($final, $rotated, 0, 0, $cropX, $cropY, $width, $height);
                imagedestroy($rotated);
                $canvas = $final;
            }

            $mapPath = $tempFolder . '/map_' . md5($centerLat . $centerLng . json_encode($markers) . $rotationDeg) . '.png';
            $saved = imagepng($canvas, $mapPath);
            imagedestroy($canvas);

            if (!$saved || !file_exists($mapPath)) {
                Log::error("[MAP] No se pudo guardar el PNG en: {$mapPath}");
                return null;
            }

            Log::info("[MAP] Mapa generado OK: {$mapPath} (" . filesize($mapPath) . " bytes)");
            return $mapPath;
        } catch (\Exception $e) {
            Log::error("[MAP] Excepción en downloadStaticMap: " . $e->getMessage());
            return null;
        }
    }

    protected function computeBearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);
        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    function get_operation_details(Operation $operation, $language){
        $isDemo = $operation->is_demo;
        $waterMark = trans('report_papi.valid', [], $language);

        if(isset($operation->getAirport()->name)){
            $name = $operation->getAirport()->name;
            $iata_code = $operation->getAirport()->iata_code;
            $icao_code = $operation->getAirport()->icao_code;
        }else{
            $name = null;
        }
        if($operation->type_id == 7){
            $subject_vor = Vor::where('id', $operation->subject_id)->first();
            $iata_code = $subject_vor->code;
        }

        $details = [
            'pilot'     => $operation->pilot->name,
            'technician'=> $operation->technician->name,
            'operator'  => $operation->operator->name,
            'airport'   => $name,
            'aircraft'  => $operation->drone->name,
            'iata_code' => $iata_code,
            'icao_code' => $operation->type_id == 7 ? '' : $icao_code,
            'header'    => $operation->subject()->name,
            'location'  => $operation->subject()->threshold_latitude . ', ' . $operation->subject()->threshold_longitude,
            'date'      => \Carbon\Carbon::parse($operation->execution_date)->format('d-M-Y'),
            'informe'   => \Carbon\Carbon::parse($operation->execution_date)->format('Y'),
            'type'      => (function() use ($operation, $language) {
                                $types = trans('report_papi.operation_types', [], $language);
                                return is_array($types) && isset($types[$operation->type->name])
                                    ? $types[$operation->type->name]
                                    : $operation->type->name;
                            })(),
            'report_id' => \Carbon\Carbon::now()->year . " - " . $operation->id,
            'is_demo'   => $isDemo,
            'config'    => ['instanceConfigurator' => function($mpdf) use ($isDemo, $waterMark){
                                $mpdf->showWatermarkText = $isDemo;
                                $mpdf->SetWatermarkText($waterMark);
                            }]
        ];

        return $details;
    }
}
