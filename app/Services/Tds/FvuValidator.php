<?php

namespace App\Services\Tds;

use Illuminate\Support\Facades\File;

/**
 * Phase 10B — a thin driver for the official Protean File Validation Utility (FVU).
 *
 * The FVU is the government's own validator. We invoke its (undocumented) command-line
 * entry point — `java -jar <FVU>.jar <input.txt> <errorReportFile> <challan.csi> 0 0 0` —
 * and capture what it reports. This is DIAGNOSTIC only: the FVU has three anti-tamper
 * barriers that make a clean automated pass on synthetic data impossible (an online
 * version self-check, a mandatory external .csi challan file we cannot synthesize for a
 * test TAN, and a barcode/hash stamp). It is designed so only the official RPU/FVU mints a
 * valid .fvu — a third-party tool like ZeroBook emits the .txt, and the filer runs the FVU
 * themselves. So {@see classify()} interprets the report: whether the run reached FIELD
 * validation, and which barrier (if any) it hit — never a green/red gate.
 */
class FvuValidator
{
    public function __construct(private SpecResolver $resolver) {}

    /** Whether a Java runtime is available to invoke the FVU at all. */
    public function javaAvailable(): bool
    {
        return $this->javaBinary() !== null;
    }

    private function javaBinary(): ?string
    {
        $candidates = [
            'C:\\Program Files (x86)\\Common Files\\Oracle\\Java\\java8path\\java.exe',
            'C:\\Program Files\\Common Files\\Oracle\\Java\\javapath\\java.exe',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        // Fall back to PATH.
        $which = @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where java 2>NUL' : 'command -v java 2>/dev/null');
        $which = trim((string) $which);
        if ($which !== '') {
            return strtok($which, "\r\n");
        }

        return null;
    }

    /**
     * Run the FVU against a generated file's text. Returns the raw report + a classification.
     *
     * @return array{ran:bool, report:string, log:string, classification:array}
     */
    public function run(string $fileText, int $fyStart): array
    {
        $java = $this->javaBinary();
        $d = $this->resolver->descriptor($fyStart);
        $fvuDir = base_path($d['fvu_dir']);
        $jar = $fvuDir.DIRECTORY_SEPARATOR.$d['fvu_jar'];

        if ($java === null || ! is_file($jar)) {
            return ['ran' => false, 'report' => '', 'log' => '', 'classification' => [
                'reached_field_validation' => false,
                'barrier' => $java === null ? 'no-java' : 'no-fvu-jar',
                'summary' => $java === null ? 'No Java runtime found to invoke the FVU.' : 'FVU jar not found at '.$jar,
            ]];
        }

        // The FVU requires the input filename to be <= 12 characters (incl. extension).
        $work = storage_path('app/tds-returns/fvu-run');
        File::ensureDirectoryExists($work);
        $input = $work.DIRECTORY_SEPARATOR.'ZB26Q.txt';         // 9 chars
        $reportFile = $work.DIRECTORY_SEPARATOR.'fvurep.txt';
        $csi = $work.DIRECTORY_SEPARATOR.'none.csi';            // deliberately absent
        @unlink($reportFile);
        @unlink($fvuDir.DIRECTORY_SEPARATOR.'fvu.log');
        file_put_contents($input, $fileText);

        // args: input, errorReportFile, csi, 0, 0, 0  (the 6-arg CLI reverse-engineered
        // from the JAR; args 3-5 are integer flags for a regular, non-correction statement).
        $cmd = sprintf(
            'cd /d %s && %s -Djava.awt.headless=true -jar %s %s %s %s 0 0 0',
            escapeshellarg($fvuDir),
            escapeshellarg($java),
            escapeshellarg($d['fvu_jar']),
            escapeshellarg($input),
            escapeshellarg($reportFile),
            escapeshellarg($csi),
        );
        @exec($cmd.' 2>&1', $out);

        $report = is_file($reportFile) ? trim((string) file_get_contents($reportFile)) : '';
        $logPath = $fvuDir.DIRECTORY_SEPARATOR.'fvu.log';
        $log = is_file($logPath) ? trim((string) file_get_contents($logPath)) : '';

        return [
            'ran' => true,
            'report' => $report,
            'log' => $log,
            'classification' => $this->classify($report, $log),
        ];
    }

    /**
     * Interpret the FVU report. A T-FV-* code means the FVU reached its FIELD validator; a
     * bare "Incorrect FVU Version of JAR" or an empty report with the version signature in
     * the log means the anti-tamper version gate stopped it before field validation.
     */
    private function classify(string $report, string $log): array
    {
        $hasFieldCode = (bool) preg_match('/T-FV-\d+/', $report);
        $versionBarrier = str_contains($report, 'Incorrect FVU Version')
            || str_contains($report, 'FVU Version is either Incorrect')
            || str_contains($log, 'Incorrect FVU Version');
        $csiBarrier = str_contains($report, 'CSI') || str_contains($report, 'Challan Input File');

        if ($versionBarrier && ! $hasFieldCode) {
            $barrier = 'version-gate';
            $summary = 'The FVU’s online anti-tamper version self-check rejected the non-official invocation '
                .'before field validation — by design, only the official RPU/FVU mints a valid .fvu.';
        } elseif ($csiBarrier) {
            $barrier = 'csi-required';
            $summary = 'The FVU reached challan verification and requires the external .csi (Challan Status '
                .'Inquiry) file from TIN — real OLTAS data that cannot be synthesised for a test TAN.';
        } elseif ($hasFieldCode) {
            $barrier = 'field-errors';
            $summary = 'The FVU reached field validation and reported field-level codes (see report).';
        } else {
            $barrier = 'unknown';
            $summary = 'The FVU produced no actionable report (see log).';
        }

        return [
            'reached_field_validation' => $hasFieldCode,
            'barrier' => $barrier,
            'summary' => $summary,
        ];
    }
}
