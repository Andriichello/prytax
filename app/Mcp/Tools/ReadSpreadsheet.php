<?php

namespace PryTax\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use PryTax\Mcp\McpSession;
use PryTax\Services\Integrations\ClaudeIntegration;
use SimpleXMLElement;
use ZipArchive;

/**
 * Class ReadSpreadsheet.
 *
 * A spreadsheet on this computer, which the person gives Claude to record in PryTax (builds.md §7): a CSV (comma,
 * semicolon or tab), a TSV or an XLSX (read from its zip and XML, its sheets by name), as rows keyed by the first
 * row's headers, empty cells null, a page at a time. Only those, by their extension: any other file stays unread.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Mcp\Tools
 */
#[Description('Read a spreadsheet file on this computer (.csv, .tsv or .xlsx) by its path: its sheets, the first row as headers, and the rows after it as objects, a page at a time (offset, limit). XLSX dates come as Excel serial numbers (days since 1899-12-30) unless the cell holds text. Use it to record a spreadsheet\'s entries in PryTax with api-write.')]
class ReadSpreadsheet extends SessionTool
{
    /**
     * The largest file it reads, in bytes.
     *
     * @var int
     */
    public const int MAX_FILE = 20 * 1024 * 1024;

    /**
     * The extensions it reads: a spreadsheet's, never another file's (`.env`, a key), which a CSV reader would give.
     *
     * @var list<string>
     */
    public const array EXTENSIONS = ['csv', 'tsv', 'xlsx'];

    /**
     * Handle the call.
     *
     * @param Request $request
     * @param McpSession $session
     * @param ClaudeIntegration $claude
     *
     * @return Response|ResponseFactory
     */
    public function handle(Request $request, McpSession $session, ClaudeIntegration $claude): Response|ResponseFactory
    {
        [$integration] = $session->open();
        $arguments = $request->validate([
            'path' => ['required', 'string', 'max:1000'],
            'sheet' => ['nullable', 'string', 'max:255'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $path = (string) $arguments['path'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['path' => 'Only a spreadsheet is read: a .csv, .tsv or .xlsx file. Save another one as CSV or XLSX first.']);
        }

        if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAX_FILE) {
            throw ValidationException::withMessages(['path' => "There's no spreadsheet of up to 20 MB to read at $path."]);
        }

        [$sheets, $sheet, $rows] = $extension === 'xlsx'
            ? $this->xlsx($path, $arguments['sheet'] ?? null)
            : [[], null, $this->csv($path, $extension === 'tsv' ? "\t" : null)];
        $headers = array_map(fn ($header, int $index) => trim((string) $header) !== '' ? trim((string) $header) : 'column_' . ($index + 1), $rows[0] ?? [], array_keys($rows[0] ?? []));
        $data = array_slice($rows, 1);
        $offset = (int) ($arguments['offset'] ?? 0);
        $page = array_slice($data, $offset, (int) ($arguments['limit'] ?? 200));
        $claude->recordUse($integration, 'read-spreadsheet');

        return Response::structured([
            'sheets' => $sheets,
            'sheet' => $sheet,
            'headers' => $headers,
            'total_rows' => count($data),
            'offset' => $offset,
            'rows' => array_map(fn (array $row) => array_combine($headers, array_pad(array_slice($row, 0, count($headers)), count($headers), null)), $page),
        ]);
    }

    /**
     * Read a CSV's rows, with the delimiter given (a TSV's tab), or the one its first line uses most; an empty cell is
     * null, as an XLSX's.
     *
     * @param string $path
     * @param string|null $delimiter
     *
     * @return list<list<string|null>>
     */
    protected function csv(string $path, ?string $delimiter = null): array
    {
        $handle = fopen($path, 'r');

        if ($delimiter === null) {
            $first = (string) fgets($handle);
            $counts = array_map(fn (string $candidate) => substr_count($first, $candidate), [',' => ',', ';' => ';', "\t" => "\t"]);
            $delimiter = (string) array_search(max($counts), $counts, true);
            rewind($handle);
        }

        // Without a UTF-8 byte order mark
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $rows = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(fn (?string $cell) => $cell === '' ? null : $cell, $row);
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Read an XLSX's sheets, and the rows of the one named (the first by default).
     *
     * @param string $path
     * @param string|null $name
     *
     * @return array{0: list<string>, 1: string|null, 2: list<list<string|null>>}
     * @throws ValidationException
     */
    protected function xlsx(string $path, ?string $name): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true || ($workbook = $zip->getFromName('xl/workbook.xml')) === false) {
            throw ValidationException::withMessages(['path' => "$path isn't an XLSX, which can be read."]);
        }

        $targets = [];
        $relations = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        foreach ($relations?->Relationship ?? [] as $relation) {
            $targets[(string) $relation['Id']] = ltrim((string) $relation['Target'], '/');
        }

        $sheets = [];
        $book = simplexml_load_string($workbook);

        foreach ($book->sheets->sheet ?? [] as $sheet) {
            $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $targets[$id] ?? '';
            $sheets[(string) $sheet['name']] = str_starts_with($target, 'xl/') ? $target : "xl/$target";
        }

        $name ??= array_key_first($sheets);

        if (!isset($sheets[$name])) {
            throw ValidationException::withMessages(['sheet' => "There's no sheet «{$name}»: " . implode(', ', array_keys($sheets)) . '.']);
        }

        $strings = $this->sharedStrings($zip);
        $xml = simplexml_load_string((string) $zip->getFromName($sheets[$name]));
        $zip->close();
        $rows = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $cells[$this->columnOf((string) $cell['r'])] = $this->valueOf($cell, $strings);
            }

            if ($cells !== []) {
                $width = max(array_keys($cells)) + 1;
                $rows[] = array_map(fn (int $column) => $cells[$column] ?? null, range(0, $width - 1));
            }
        }

        return [array_keys($sheets), $name, $rows];
    }

    /**
     * Get an XLSX's shared strings, by their index.
     *
     * @param ZipArchive $zip
     *
     * @return list<string>
     */
    protected function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach (simplexml_load_string($xml)->si ?? [] as $item) {
            $strings[] = isset($item->t) ? (string) $item->t : implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($item->r, false)));
        }

        return $strings;
    }

    /**
     * Get a cell's value: a shared or inline string, a boolean, or the number as written.
     *
     * @param SimpleXMLElement $cell
     * @param list<string> $strings
     *
     * @return string|null
     */
    protected function valueOf(SimpleXMLElement $cell, array $strings): ?string
    {
        return match ((string) $cell['t']) {
            's' => $strings[(int) $cell->v] ?? null,
            'inlineStr' => (string) $cell->is->t,
            'b' => (string) $cell->v === '1' ? 'true' : 'false',
            default => isset($cell->v) ? (string) $cell->v : null,
        };
    }

    /**
     * Get the index of a cell's column, from its reference: `C7` → 2.
     *
     * @param string $reference
     *
     * @return int
     */
    protected function columnOf(string $reference): int
    {
        $index = 0;

        foreach (str_split((string) preg_replace('/\d+/', '', $reference)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Get the tool's input schema.
     *
     * @param JsonSchema $schema
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->required()->description('The file\'s full path on this computer.'),
            'sheet' => $schema->string()->description('An XLSX\'s sheet, by name; the first by default.'),
            'offset' => $schema->integer()->description('The first row (after the headers) to give, 0 by default.'),
            'limit' => $schema->integer()->description('How many rows, 200 by default, at most 1000.'),
        ];
    }
}
