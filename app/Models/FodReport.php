<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class FodReport extends Report {

    use HasFactory;

    public function generate(Operation $operation, $language, $angle_unit, $stream = true)
    {
        $details = $this->get_operation_details($operation, $language);
        $details['title'] = trans('report_fod.title', [], $language);

        $operator = Operator::where('id', $operation['operator_id'])->get();

        $folderMapping = Operation::getFolderMapping();

        // Load FOD types for label mapping
        $fodTypes = FodType::all()->keyBy('id');

        // Temp folder for images
        $tempFolderPath = storage_path("app/temp/fod_" . $operation->id . '_' . uniqid());
        File::ensureDirectoryExists($tempFolderPath);

        // Load all images for this operation, ordered by id, with their detections
        $fodImages = FodImage::whereHas('fod', function ($query) use ($operation) {
                $query->where('operation_id', $operation->id);
            })
            ->with('fodDetections')
            ->orderBy('id')
            ->get();

        // Build pages data: one entry per image
        $imagePages = [];
        foreach ($fodImages as $index => $fodImage) {

            // Download, annotate with bboxes, and optimize main image
            $localImagePath = null;
            $s3ImagePath = ltrim($fodImage->image_path, '/');
            if (Storage::disk('s3')->exists($s3ImagePath)) {
                $imageContent   = Storage::disk('s3')->get($s3ImagePath);
                $tempRawPath    = $tempFolderPath . '/' . uniqid() . '_raw_' . basename($s3ImagePath);
                File::put($tempRawPath, $imageContent);
                $annotatedPath  = $this->drawDetections($tempRawPath, $fodImage->fodDetections, $tempFolderPath);
                $localImagePath = $this->optimizeImage($annotatedPath, $tempFolderPath);
                File::delete($tempRawPath);
                if ($annotatedPath !== $tempRawPath) {
                    File::delete($annotatedPath);
                }
            }

            // Build detections with patch URLs
            $detections = [];
            foreach ($fodImage->fodDetections as $detection) {
                $patchPath = dirname($s3ImagePath) . '/patches/' . $detection->id . '.jpg';
                $patchUrl  = null;
                if (Storage::disk('s3')->exists($patchPath)) {
                    $patchUrl = Storage::disk('s3')->temporaryUrl($patchPath, Carbon::now()->addHour());
                }

                $detections[] = [
                    'detection_number' => $detection->detection_number,
                    'size'             => number_format($detection->bbox_dim_cm_width, 1) . ' x ' . number_format($detection->bbox_dim_cm_height, 1),
                    'type'             => isset($fodTypes[$detection->type_id]) ? $fodTypes[$detection->type_id]->type : 'Unknown',
                    'confidence'       => $detection->confidence,
                    'latitude'         => number_format($detection->coordinate_latitude, 7),
                    'longitude'        => number_format($detection->coordinate_longitude, 7),
                    'detection_type'   => $detection->detection_type,
                    'patch_url'        => $patchUrl,
                ];
            }

            $imagePages[] = [
                'num'        => $index + 1,
                'local_path' => $localImagePath,
                'detections' => $detections,
            ];
        }

        // Temporary directory for MPDF
        File::ensureDirectoryExists(storage_path("app/temp/mpdf"));

        $mpdfConfig = [
            'tempDir' => storage_path("app/temp/mpdf")
        ];

        $fileName = 'fod_report_op_' . $operation->id . '.pdf';

        // Generate PDF with MPDF
        $pdf = new Mpdf($mpdfConfig);
        $viewData = compact('language', 'details', 'operator', 'operation', 'angle_unit', 'imagePages');
        $htmlContent = view('reports.fod', $viewData)->render();
        $pdf->WriteHTML($htmlContent);

        // Save to temporary path
        $tempPath = storage_path("app/temp/" . uniqid('report_', true) . ".pdf");
        File::ensureDirectoryExists(dirname($tempPath));
        $pdf->Output($tempPath, 'F');

        // Clean up optimized image temp files
        File::deleteDirectory($tempFolderPath);

        // Generate unique filename in S3
        $s3FileName = $fileName;
        $s3PdfPath  = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
        $counter    = 1;

        while (Storage::disk('s3')->exists($s3PdfPath)) {
            $s3FileName = pathinfo($fileName, PATHINFO_FILENAME) . "_{$counter}." . pathinfo($fileName, PATHINFO_EXTENSION);
            $s3PdfPath  = "{$folderMapping[$operation->type_id]}/{$operation->id}/reports/{$s3FileName}";
            $counter++;
        }

        // Upload to S3
        Storage::disk('s3')->put($s3PdfPath, File::get($tempPath));

        // Save to database
        OperationReports::create([
            'name'         => $s3FileName,
            'description'  => '',
            'type'         => 'pdf',
            'size'         => File::size($tempPath),
            'operation_id' => $operation->id,
        ]);

        // Delete temporary PDF
        File::delete($tempPath);

        return Storage::disk('s3')->response($s3PdfPath);
    }

    private function drawDetections($sourcePath, $detections, $outputFolder)
    {
        $img = $this->icreate($sourcePath);
        if (!$img || $detections->isEmpty()) {
            return $sourcePath;
        }

        $imgWidth  = imagesx($img);
        $imgHeight = imagesy($img);
        $fontPath  = public_path('fonts/arial.ttf');
        $green     = imagecolorallocate($img, 51, 255, 51);
        $black     = imagecolorallocate($img, 0, 0, 0);

        $thickness = max(3, (int)($imgWidth / 400));
        $fontSize  = max(16, (int)($imgWidth / 80));

        foreach ($detections as $detection) {
            $cx = $detection->bbox_x;
            $cy = $detection->bbox_y;
            $w  = $detection->bbox_width;
            $h  = $detection->bbox_height;

            $x1 = (int)($cx - $w / 2);
            $y1 = (int)($cy - $h / 2);
            $x2 = (int)($cx + $w / 2);
            $y2 = (int)($cy + $h / 2);

            if ($x1 < 0 || $y1 < 0 || $x2 > $imgWidth || $y2 > $imgHeight) {
                continue;
            }

            for ($i = 0; $i < $thickness; $i++) {
                imagerectangle($img, $x1 - $i, $y1 - $i, $x2 + $i, $y2 + $i, $green);
            }

            $label = (string)$detection->detection_number;
            $bbox  = imagettfbbox($fontSize, 0, $fontPath, $label);
            $tw    = abs($bbox[2] - $bbox[0]);
            $th    = abs($bbox[1] - $bbox[7]);
            $pad   = 6;
            $lx    = $x1;
            $ly    = max($y1 - $pad, $th + $pad * 2);

            imagefilledrectangle($img, $lx - $pad, $ly - $th - $pad, $lx + $tw + $pad, $ly + $pad, $green);
            imagettftext($img, $fontSize, 0, $lx, $ly, $black, $fontPath, $label);
        }

        $outPath = $outputFolder . '/' . uniqid('annot_') . '.jpg';
        imagejpeg($img, $outPath, 90);
        imagedestroy($img);

        return $outPath;
    }

    private function optimizeImage($sourcePath, $outputFolder)
    {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return $sourcePath;
        }

        $sourceImage = $this->icreate($sourcePath);
        if (!$sourceImage) {
            return $sourcePath;
        }

        $originalWidth  = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        // Max dimensions for PDF
        $maxWidth  = 1150;
        $maxHeight = 900;

        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);

        if ($ratio < 1) {
            $newWidth  = (int)($originalWidth * $ratio);
            $newHeight = (int)($originalHeight * $ratio);
        } else {
            $newWidth  = $originalWidth;
            $newHeight = $originalHeight;
        }

        $optimizedImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($imageInfo['mime'] == 'image/png') {
            imagealphablending($optimizedImage, false);
            imagesavealpha($optimizedImage, true);
            $transparent = imagecolorallocatealpha($optimizedImage, 255, 255, 255, 127);
            imagefilledrectangle($optimizedImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled(
            $optimizedImage, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        $optimizedPath = $outputFolder . '/' . uniqid('opt_') . '.jpg';
        imagejpeg($optimizedImage, $optimizedPath, 75);

        imagedestroy($sourceImage);
        imagedestroy($optimizedImage);

        return $optimizedPath;
    }

    private function icreate($filename)
    {
        $isize = getimagesize($filename);
        if ($isize['mime'] == 'image/jpeg') {
            return imagecreatefromjpeg($filename);
        } elseif ($isize['mime'] == 'image/png') {
            return imagecreatefrompng($filename);
        } elseif ($isize['mime'] == 'image/gif') {
            return imagecreatefromgif($filename);
        } elseif ($isize['mime'] == 'image/webp') {
            return imagecreatefromwebp($filename);
        }
        return false;
    }
}
