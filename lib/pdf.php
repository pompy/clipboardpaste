<?php
require('../fpdf/fpdf.php');

class PDF extends FPDF
{
    function Header()
    {
        // Uncomment if you want headers on each page
        // $this->SetFont('Arial','B',15);
        // $this->Cell(0,10,'Image Collection',0,1,'C');
    }

    function Footer()
    {
        // Uncomment if you want footers on each page
        // $this->SetY(-15);
        // $this->SetFont('Arial','I',8);
        // $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }

    function addImagePage($imagePath, $caption, $imageType)
    {
        $this->AddPage();
        
        // Add caption
        $this->SetFont('Arial','B',14);
        $this->MultiCell(0, 10, $caption, 0, 'C');
        $this->Ln(10);

        // Calculate image dimensions
        $maxWidth = 180; // Max width in mm
        $maxHeight = $this->GetPageHeight() - $this->GetY() - 20; // Space left on page
        
        list($width, $height) = getimagesize($imagePath);
        $ratio = $width / $height;
        
        $displayWidth = $maxWidth;
        $displayHeight = $displayWidth / $ratio;
        
        if ($displayHeight > $maxHeight) {
            $displayHeight = $maxHeight;
            $displayWidth = $displayHeight * $ratio;
        }

        // Center the image
        $x = ($this->GetPageWidth() - $displayWidth) / 2;
        
        // Add image with proper type
        $this->Image($imagePath, $x, $this->GetY(), $displayWidth, $displayHeight, $imageType);
    }
}

// Process the request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_pdf') {
    // Create temporary directory
    $tempDir = sys_get_temp_dir() . '/pdf_images';
    if (!file_exists($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // Clean old files
    foreach (glob($tempDir . '/*') as $file) {
        if (filemtime($file) < time() - 3600) {
            unlink($file);
        }
    }

    $pdf = new PDF();
    $pdf->AliasNbPages();

    if (!empty($_FILES['images']['tmp_name'][0])) {
        $images = $_FILES['images'];
        $captions = $_POST['captions'];

        // Reorder images and captions based on their original order
        $orderedImages = [];
        foreach ($images['name'] as $key => $value) {
            $orderedImages[$key] = [
                'tmp_name' => $images['tmp_name'][$key],
                'name' => $images['name'][$key],
                'error' => $images['error'][$key],
                'caption' => $captions[$key] ?? 'Untitled',
            ];
        }

        // Process images in order
        foreach ($orderedImages as $image) {
            if ($image['error'] === UPLOAD_ERR_OK) {
                // Verify the file is an actual image
                $imageInfo = @getimagesize($image['tmp_name']);
                if ($imageInfo === false) {
                    continue; // Skip non-image files
                }

                // Determine image type
                $imageType = '';
                $ext = '';
                switch ($imageInfo[2]) {
                    case IMAGETYPE_JPEG:
                        $imageType = 'JPEG';
                        $ext = 'jpg';
                        break;
                    case IMAGETYPE_PNG:
                        $imageType = 'PNG';
                        $ext = 'png';
                        break;
                    case IMAGETYPE_GIF:
                        $imageType = 'GIF';
                        $ext = 'gif';
                        break;
                    default:
                        continue; // Skip unsupported types
                }

                // Create unique filename
                $tempFilePath = $tempDir . '/img_' . uniqid() . '.' . $ext;

                // Move the temporary file
                if (!move_uploaded_file($image['tmp_name'], $tempFilePath)) {
                    continue; // Skip if move failed
                }

                // Verify the file was actually created
                if (!file_exists($tempFilePath) || filesize($tempFilePath) === 0) {
                    continue;
                }

                // Get caption (with basic sanitization)
                $caption = filter_var($image['caption'], FILTER_SANITIZE_STRING);

                // Add to PDF
                $pdf->addImagePage($tempFilePath, $caption, $imageType);

                // Clean up
                unlink($tempFilePath);
            }
        }
    }

    // Final output
    $pdfFileName = $tempDir . '/output_' . uniqid() . '.pdf';
    $pdf->Output('F', $pdfFileName);

    if (file_exists($pdfFileName)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="images.pdf"');
        header('Content-Length: ' . filesize($pdfFileName));
        readfile($pdfFileName);
        unlink($pdfFileName);
        exit;
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Failed to generate PDF';
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    echo 'Invalid request';
}
?>
