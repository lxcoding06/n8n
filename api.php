<?php

require_once __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json');

$geminiApiKey = getenv('GEMINI_API_KEY') ?: "TARUH_APIKEY_GEMINIMU_DISINI";
$geminiApiUrlBase = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent";
$timeoutSeconds = 180;

$googleSpreadsheetId = '1O7Gn9BLwAKRN8OHi6stOR2LMPB4MvzkBvQ6VQ8iqDwo';
$googleServiceAccountKeyJsonPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '/home/ailxcoding/google_keys/lxproject-76e50-f0cb5537798b.json';
$sheetNameForLookup = 'Sheet1';

function sendJsonResponse($data, $httpStatusCode = 200) {
    http_response_code($httpStatusCode);
    echo json_encode($data);
    exit;
}

function parseGeminiOutput($text) {
    $parsedData = [
        'hostname' => null,
        'var' => null,
        'shared' => null,
        'config' => null,
        'tmm_memory_avg' => null,
        'other_memory_avg' => null,
        'swap_used_avg' => null,
        'cpu_average' => null,
        'throughput_in_avg_raw' => null,
    ];
    $text = preg_replace('/^Berikut outputnya:\s*/i', '', trim($text));

    if (preg_match('/HOSTNAME\s*:\s*(?:[^@,\s]+@)?([\w.-]+)/i', $text, $matches)) {
        $parsedData['hostname'] = trim($matches[1]);
    } else {
        if (preg_match('/^([\w.-]+)\s*,(?=\s*(?:VAR|SHARED|CONFIG|TMM_MEMORY_AVG|OTHER_MEMORY_AVG|SWAP_USED_AVG|CPU_AVERAGE|THROUGHPUT_IN_AVG_RAW)\s*:)/i', $text, $matches)) {
            $potentialHostname = trim($matches[1]);
            if (!preg_match('/^[\d.]+[GMK]$/i', $potentialHostname) && strlen($potentialHostname) > 0) {
                $parsedData['hostname'] = $potentialHostname;
            }
        }
    }

    $paths = ['var', 'shared', 'config'];
    foreach ($paths as $path) {
        if (preg_match('/\b' . preg_quote($path, '/') . '\s*:\s*([\d.]+%?)/i', $text, $matches)) {
            $value = trim($matches[1]);
            if (is_numeric(str_replace('%', '', $value)) && !str_ends_with($value, '%')) {
            }
            $parsedData[$path] = $value;
        }
    }

    if (preg_match('/TMM_MEMORY_AVG\s*:\s*([\d.]+)/i', $text, $matches)) {
        $parsedData['tmm_memory_avg'] = trim($matches[1]);
    }
    if (preg_match('/OTHER_MEMORY_AVG\s*:\s*([\d.]+)/i', $text, $matches)) {
        $parsedData['other_memory_avg'] = trim($matches[1]);
    }
    if (preg_match('/SWAP_USED_AVG\s*:\s*([\d.]+)/i', $text, $matches)) {
        $parsedData['swap_used_avg'] = trim($matches[1]);
    }

    if (preg_match('/CPU_AVERAGE\s*:\s*([\d.]+)/i', $text, $matches)) {
        $parsedData['cpu_average'] = trim($matches[1]);
    }

    if (preg_match('/THROUGHPUT_IN_AVG_RAW\s*:\s*([\d.]+[GMK]?)/i', $text, $matches)) {
        $parsedData['throughput_in_avg_raw'] = trim($matches[1]);
    }

    return $parsedData;
}

if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    sendJsonResponse(['error' => 'Metode permintaan tidak valid. Hanya POST yang diizinkan.'], 405);
}
if (empty($geminiApiKey) || $geminiApiKey === "YOUR_GEMINI_API_KEY_FALLBACK") {
    error_log("Kesalahan konfigurasi API (Gemini): API Key tidak ada atau placeholder.");
    sendJsonResponse(['error' => 'Kesalahan konfigurasi API (Gemini) pada server.'], 500);
}
if (empty($googleServiceAccountKeyJsonPath) || !file_exists($googleServiceAccountKeyJsonPath) || $googleServiceAccountKeyJsonPath === '/path/to/your/secure/service-account-key.json') {
    error_log("Kesalahan konfigurasi API (Google Sheets): Path key JSON tidak valid atau file tidak ditemukan. Path: " . $googleServiceAccountKeyJsonPath);
    sendJsonResponse(['error' => 'Kesalahan konfigurasi API (Google Sheets) pada server.'], 500);
}

$jsonInput = file_get_contents('php://input');
$inputData = json_decode($jsonInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    sendJsonResponse(['error' => 'Request body bukan JSON yang valid. Error: ' . json_last_error_msg()], 400);
}

if (!isset($inputData['image_base64']) || !isset($inputData['mime_type'])) {
    sendJsonResponse(['error' => 'Parameter `image_base64` (string base64 gambar) dan `mime_type` (contoh: image/jpeg) diperlukan dalam JSON body.'], 400);
}

$base64Image = $inputData['image_base64'];
$imageMimeType = $inputData['mime_type'];

if (empty($base64Image) || !preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $base64Image) || base64_decode($base64Image, true) === false) {
    sendJsonResponse(['error' => 'String `image_base64` tidak valid atau kosong.'], 400);
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($imageMimeType, $allowedMimeTypes)) {
    sendJsonResponse(['error' => "Tipe MIME '{$imageMimeType}' tidak didukung. Hanya mendukung: " . implode(', ', $allowedMimeTypes)], 415);
}

$userPrompt = "Dari gambar ini, ekstrak informasi berikut. " .
              "Untuk HOSTNAME: dari prompt perintah seperti '[user@hostname:status]', '[user@hostname]', atau 'user@hostname>', ekstrak bagian 'hostname' (contoh: RGN-DZ-LB1 dari '[...02@RGN-DZ-LB1:Active...]'). Jika format prompt berbeda, ambil nama host yang paling jelas dari header atau baris perintah awal. Prioritaskan bentuk pendek nama host (misalnya 'RGN-DZ-LB1') daripada FQDN ('RGN-DZ-LB1.domain.com'), kecuali jika FQDN adalah satu-satunya yang terlihat. Format outputnya adalah HOSTNAME: nama_host_hasil_ekstraksi." .
              "Untuk VAR, SHARED, dan CONFIG, berikan nilai persentase penggunaannya (nilai 'Use%'). Formatnya adalah VAR: persen_var%, SHARED: persen_shared%, CONFIG: persen_config%. Contoh: VAR: 13%, SHARED: 45%, CONFIG: 3%. " .
              "Jika gambar juga mengandung informasi 'Memory Used', ekstrak nilai rata-rata (average) untuk 'TMM Memory', 'Other Memory', dan 'Swap Used'. Sajikan data memori ini dengan format: TMM_MEMORY_AVG: nilai_tmm, OTHER_MEMORY_AVG: nilai_other, SWAP_USED_AVG: nilai_swap. Nilai harus berupa angka (misalnya 12.34 atau 50). " .
              "Jika gambar menampilkan 'System CPU Usage', ekstrak nilai 'Average' untuk System CPU Usage. Sajikan data CPU ini dengan format: CPU_AVERAGE: nilai_cpu_average. Nilai harus berupa angka (misalnya 24). " .
              "Jika gambar menampilkan tabel 'Sys::Performance Throughput' yang mencakup sub-bagian 'Throughput(bits)/(bits/sec)': Di dalam sub-bagian 'Throughput(bits)/(bits/sec)' tersebut, cari baris yang labelnya adalah 'In'. Dari baris 'In' ini, ekstrak nilai yang berada tepat di bawah heading kolom 'Average'. Ambil nilai ini apa adanya (contoh: 2.1M, 2.0G, 728K). Sajikan data throughput ini dengan format: THROUGHPUT_IN_AVG_RAW: nilai_hasil_ekstraksi. " .
              "Sajikan semua informasi yang berhasil diekstrak dalam satu baris, pisahkan setiap item dengan koma dan spasi. Urutan: HOSTNAME (jika ada), lalu VAR (jika ada), SHARED (jika ada), CONFIG (jika ada), lalu TMM_MEMORY_AVG (jika ada), OTHER_MEMORY_AVG (jika ada), SWAP_USED_AVG (jika ada), lalu CPU_AVERAGE (jika ada), lalu THROUGHPUT_IN_AVG_RAW (jika ada). Hanya tulis informasi yang diminta dalam format yang telah ditentukan. Jika suatu bagian tidak ada, jangan sertakan bagian tersebut (misalnya jika tidak ada data CPU, jangan tulis CPU_AVERAGE:).";

$payload = [
    "contents" => [["parts" => [["text" => $userPrompt], ["inline_data" => ["mime_type" => $imageMimeType, "data" => $base64Image]]]]],
    "generationConfig" => ["maxOutputTokens" => 800, "temperature" => 0.1],
];
$headers = ["Content-Type: application/json"];
$fullApiUrl = $geminiApiUrlBase . "?key=" . $geminiApiKey;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $fullApiUrl, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeoutSeconds,
]);
$geminiResponse = curl_exec($ch);
$httpStatusCodeGemini = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrorNo = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlErrorNo) {
    error_log("cURL Error to Gemini: #" . $curlErrorNo . " - " . $curlError);
    sendJsonResponse(['error' => "Gagal menghubungi layanan Google Gemini: {$curlError}"], 502);
}
$responseDataGemini = json_decode($geminiResponse, true);
if ($httpStatusCodeGemini >= 400 || isset($responseDataGemini['error'])) {
    $apiErrorMsg = $responseDataGemini['error']['message'] ?? $geminiResponse;
    error_log("Error from Google Gemini API (HTTP {$httpStatusCodeGemini}): " . $apiErrorMsg);
    sendJsonResponse(['error' => "Error dari Google Gemini API: {$apiErrorMsg}", 'gemini_http_status' => $httpStatusCodeGemini], (int)$httpStatusCodeGemini >= 500 ? 502 : $httpStatusCodeGemini);
}
if (isset($responseDataGemini['promptFeedback']['blockReason'])) {
    $blockReason = $responseDataGemini['promptFeedback']['blockReason'];
    error_log("Konten diblokir oleh Gemini API: " . $blockReason);
    sendJsonResponse(['error' => "Konten diblokir oleh Gemini API karena alasan: {$blockReason}."], 400);
}
if (json_last_error() !== JSON_ERROR_NONE || !isset($responseDataGemini['candidates'][0]['content']['parts'][0]['text'])) {
    $finishReason = $responseDataGemini['candidates'][0]['finishReason'] ?? 'tidak diketahui';
    if ($finishReason !== 'STOP' && $finishReason !== 'MAX_TOKENS') {
        error_log("Gemini API tidak menghasilkan output yang valid. Finish reason: " . $finishReason . ". Response: " . $geminiResponse);
        sendJsonResponse(['error' => "Gemini API tidak menghasilkan output yang valid atau format tidak sesuai. Finish Reason: " . $finishReason], 502);
    } else if (!isset($responseDataGemini['candidates'][0]['content']['parts'][0]['text'])) {
        error_log("Format respons Gemini tidak sesuai (tidak ada teks output). Response: " . $geminiResponse);
        sendJsonResponse(['error' => 'Format respons Gemini tidak sesuai (tidak ada teks output).'], 502);
    }
}

$geminiOutputText = $responseDataGemini['candidates'][0]['content']['parts'][0]['text'];
$parsedSheetData = parseGeminiOutput($geminiOutputText);
$sheetUpdateStatus = "Belum ada upaya update ke sheet.";

$canUpdateDisk = (isset($parsedSheetData['var']) && $parsedSheetData['var'] !== null) ||
                 (isset($parsedSheetData['shared']) && $parsedSheetData['shared'] !== null) ||
                 (isset($parsedSheetData['config']) && $parsedSheetData['config'] !== null);

$canUpdateMemory = (isset($parsedSheetData['tmm_memory_avg']) && $parsedSheetData['tmm_memory_avg'] !== null) ||
                   (isset($parsedSheetData['other_memory_avg']) && $parsedSheetData['other_memory_avg'] !== null) ||
                   (isset($parsedSheetData['swap_used_avg']) && $parsedSheetData['swap_used_avg'] !== null);

$canUpdateCpu = isset($parsedSheetData['cpu_average']) && $parsedSheetData['cpu_average'] !== null;
$canUpdateThroughput = isset($parsedSheetData['throughput_in_avg_raw']) && $parsedSheetData['throughput_in_avg_raw'] !== null;

if (isset($parsedSheetData['hostname']) && $parsedSheetData['hostname'] !== null && ($canUpdateDisk || $canUpdateMemory || $canUpdateCpu || $canUpdateThroughput)) {
    try {
        $client = new Google_Client();
        $client->setAuthConfig($googleServiceAccountKeyJsonPath);
        $client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
        $service = new Google_Service_Sheets($client);

        $hostnameColumnRange = $sheetNameForLookup . '!B3:B57';
        $response = $service->spreadsheets_values->get($googleSpreadsheetId, $hostnameColumnRange);
        $hostnamesInSheet = $response->getValues();
        $targetRowNumber = -1;

        if (!empty($hostnamesInSheet)) {
            $geminiExtractedHostnameOriginal = trim($parsedSheetData['hostname']);
            
            $geminiStrtokCopy = $geminiExtractedHostnameOriginal; 
            $geminiShortHostnameLower = strtolower(strtok($geminiStrtokCopy, '.'));

            foreach ($hostnamesInSheet as $rowIndex => $rowData) {
                if (isset($rowData[0]) && !empty(trim($rowData[0]))) {
                    $sheetFullHostnameOriginal = trim($rowData[0]);
                    
                    $sheetStrtokCopy = $sheetFullHostnameOriginal;
                    $sheetShortHostnameLower = strtolower(strtok($sheetStrtokCopy, '.'));

                    if ($geminiShortHostnameLower === $sheetShortHostnameLower) {
                        $targetRowNumber = $rowIndex + 3;
                        break;
                    }
                }
            }
        }

        if ($targetRowNumber != -1) {
            $dataToUpdate = [];

            if (isset($parsedSheetData['var']) && $parsedSheetData['var'] !== null) {
                $numericVar = str_replace('%', '', $parsedSheetData['var']);
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!H' . $targetRowNumber,
                    'values' => [[$numericVar]]
                ]);
            }
            if (isset($parsedSheetData['shared']) && $parsedSheetData['shared'] !== null) {
                $numericShared = str_replace('%', '', $parsedSheetData['shared']);
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!I' . $targetRowNumber,
                    'values' => [[$numericShared]]
                ]);
            }
            if (isset($parsedSheetData['config']) && $parsedSheetData['config'] !== null) {
                $numericConfig = str_replace('%', '', $parsedSheetData['config']);
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!J' . $targetRowNumber,
                    'values' => [[$numericConfig]]
                ]);
            }

            if (isset($parsedSheetData['tmm_memory_avg']) && $parsedSheetData['tmm_memory_avg'] !== null) {
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!E' . $targetRowNumber,
                    'values' => [[$parsedSheetData['tmm_memory_avg']]]
                ]);
            }
            if (isset($parsedSheetData['other_memory_avg']) && $parsedSheetData['other_memory_avg'] !== null) {
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!F' . $targetRowNumber,
                    'values' => [[$parsedSheetData['other_memory_avg']]]
                ]);
            }
            if (isset($parsedSheetData['swap_used_avg']) && $parsedSheetData['swap_used_avg'] !== null) {
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!G' . $targetRowNumber,
                    'values' => [[$parsedSheetData['swap_used_avg']]]
                ]);
            }

            if (isset($parsedSheetData['cpu_average']) && $parsedSheetData['cpu_average'] !== null) {
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!D' . $targetRowNumber,
                    'values' => [[$parsedSheetData['cpu_average']]]
                ]);
            }
            
            if (isset($parsedSheetData['throughput_in_avg_raw']) && $parsedSheetData['throughput_in_avg_raw'] !== null) {
                $dataToUpdate[] = new Google_Service_Sheets_ValueRange([
                    'range' => $sheetNameForLookup . '!K' . $targetRowNumber,
                    'values' => [[$parsedSheetData['throughput_in_avg_raw']]]
                ]);
            }

            if (!empty($dataToUpdate)) {
                $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'USER_ENTERED',
                    'data' => $dataToUpdate
                ]);
                $updateResponse = $service->spreadsheets_values->batchUpdate($googleSpreadsheetId, $batchUpdateRequest);
                $updatedCellsCount = ($updateResponse && method_exists($updateResponse, 'getTotalUpdatedCells')) ? $updateResponse->getTotalUpdatedCells() : 'tidak diketahui';
                $sheetUpdateStatus = "Sheet berhasil diupdate untuk hostname: " . ($parsedSheetData['hostname'] ?? 'N/A') . " (target baris: {$targetRowNumber}). Total sel diupdate: " . $updatedCellsCount;
            } else {
                $sheetUpdateStatus = "Tidak ada data yang valid (disk, memori, CPU, atau throughput) untuk diupdate pada hostname: " . ($parsedSheetData['hostname'] ?? 'N/A');
                error_log($sheetUpdateStatus . " Data parsed: " . json_encode($parsedSheetData));
            }
        } else {
            $sheetUpdateStatus = "Hostname '" . ($parsedSheetData['hostname'] ?? 'TIDAK DIKETAHUI') . "' tidak ditemukan dalam kolom B (B3:B57) di sheet '" . $sheetNameForLookup . "' setelah pencocokan nama pendek.";
            if (isset($parsedSheetData['hostname'])) { 
                error_log($sheetUpdateStatus . " Hostname dari Gemini: " . $parsedSheetData['hostname'] . ". Output Gemini Asli: " . $geminiOutputText);
            } else {
                error_log($sheetUpdateStatus . " Hostname tidak berhasil diparsing dari Gemini. Output Gemini Asli: " . $geminiOutputText);
            }
        }
    } catch (Exception $e) {
        $sheetUpdateStatus = "Gagal mengupdate sheet: " . $e->getMessage();
        error_log("Google Sheets API Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    }
} else {
    $missingInfo = [];
    if (!isset($parsedSheetData['hostname']) || $parsedSheetData['hostname'] === null) {
        $missingInfo[] = "hostname";
        $sheetUpdateStatus = "Update sheet dilewati karena hostname tidak berhasil diekstrak dari output Gemini.";
    } else { 
        $sheetUpdateStatus = "Update sheet dilewati. Hostname '" . $parsedSheetData['hostname'] . "' terdeteksi, tetapi tidak ada data lain (disk, memori, CPU, throughput) yang valid untuk diupdate.";
    }
    
    if (!$canUpdateDisk && !$canUpdateMemory && !$canUpdateCpu && !$canUpdateThroughput && !(isset($parsedSheetData['hostname']) && $parsedSheetData['hostname'] !== null) ) {
        $missingInfo[] = "data persentase (var/shared/config) atau data memori (tmm/other/swap) atau data CPU atau data Throughput";
    }

    if (!empty($missingInfo)) {
        $sheetUpdateStatus = "Update sheet dilewati karena informasi tidak lengkap dari output Gemini (kurang: " . implode(" dan ", $missingInfo) . ").";
    }
    error_log($sheetUpdateStatus . " Output Gemini Asli: '" . $geminiOutputText . "' Hasil Parse: " . json_encode($parsedSheetData));
}

sendJsonResponse([
    'gemini_description' => trim($geminiOutputText),
    'parsed_for_sheet' => $parsedSheetData,
    'sheet_update_status' => $sheetUpdateStatus
]);

?>