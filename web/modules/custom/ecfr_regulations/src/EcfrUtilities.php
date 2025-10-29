<?php

namespace Drupal\ecfr_regulations;

use Drupal\Core\File\FileSystemInterface;

/**
 * Utility class for eCFR common operations.
 */
class EcfrUtilities {

  /**
   * Normalize a chapter identifier.
   *
   * If purely numeric, convert to an uppercase Roman numeral.
   */
  public static function normalizeChapterIdentifier(string $chapter): string {
    $trim = trim($chapter);
    if ($trim === '') {
      return $trim;
    }
    // If it's purely Roman numerals, uppercase them for consistency.
    if (preg_match('/^[ivxlcdm]+$/i', $trim)) {
      return strtoupper($trim);
    }
    // Otherwise, return as-is (numeric, alphanumeric, etc.)
    return $trim;
  }

  /**
   * Convert integer (1..3999) to Roman numeral.
   */
  public static function intToRoman(int $num): string {
    if ($num <= 0) {
      return (string) $num;
    }
    $map = [
      'M' => 1000,
      'CM' => 900,
      'D' => 500,
      'CD' => 400,
      'C' => 100,
      'XC' => 90,
      'L' => 50,
      'XL' => 40,
      'X' => 10,
      'IX' => 9,
      'V' => 5,
      'IV' => 4,
      'I' => 1,
    ];
    $res = '';
    foreach ($map as $roman => $val) {
      while ($num >= $val) {
        $res .= $roman;
        $num -= $val;
      }
    }
    return $res;
  }

  /**
   * Convert Roman numeral to integer.
   */
  public static function romanToInt(string $roman): int {
    $roman = strtoupper($roman);
    $map = [
      'I' => 1,
      'V' => 5,
      'X' => 10,
      'L' => 50,
      'C' => 100,
      'D' => 500,
      'M' => 1000,
    ];

    $result = 0;
    $prev = 0;

    for ($i = strlen($roman) - 1; $i >= 0; $i--) {
      $current = $map[$roman[$i]] ?? 0;
      if ($current < $prev) {
        $result -= $current;
      }
      else {
        $result += $current;
      }
      $prev = $current;
    }

    return $result;
  }

  /**
   * Normalize agency structure for internal storage.
   */
  public static function normalizeAgency(array $agency): array {
    $normalized = [
      'name' => $agency['display_name'] ?? $agency['name'] ?? NULL,
      'short_name' => $agency['short_name'] ?? NULL,
      'slug' => $agency['slug'] ?? NULL,
      'cfr_references' => $agency['cfr_references'] ?? [],
      'children' => [],
    ];

    if (!empty($agency['children']) && is_array($agency['children'])) {
      foreach ($agency['children'] as $child) {
        if (is_array($child)) {
          $normalized['children'][] = static::normalizeAgency($child);
        }
      }
    }

    return $normalized;
  }

  /**
   * Convert agencies into a hierarchical tree keyed by parent reference.
   */
  public static function buildAgencyTree(array $agencies): array {
    $tree = [];
    $lookup = [];
    foreach ($agencies as $agency) {
      $lookup[$agency->id()] = ['agency' => $agency, 'children' => []];
    }
    foreach ($lookup as $id => $node) {
      $parentId = $node['agency']->get('parent')->target_id ?? NULL;
      if ($parentId && isset($lookup[$parentId])) {
        $lookup[$parentId]['children'][] = &$lookup[$id];
      }
      else {
        $tree[] = &$lookup[$id];
      }
    }
    return $tree;
  }

  /**
   * Convert an agency tree into table rows for analytics pages.
   */
  public static function buildAgencyTableRows(array $tree, callable $rowBuilder): array {
    $rows = [];
    foreach ($tree as $node) {
      static::addAgencyRow($node, $rows, 0, $rowBuilder);
    }
    return $rows;
  }

  /**
   * Recursive helper for agency row building.
   */
  protected static function addAgencyRow(array $node, array &$rows, int $depth, callable $rowBuilder): void {
    $rows[] = $rowBuilder($node['agency'], $depth);
    foreach ($node['children'] as $child) {
      static::addAgencyRow($child, $rows, $depth + 1, $rowBuilder);
    }
  }

  /**
   * Extract a chapter node from a SimpleXML document.
   *
   * @param \SimpleXMLElement $xml
   *   The full XML document.
   * @param array $possibleMatches
   *   Candidate chapter identifiers to match against.
   *
   * @return \SimpleXMLElement|null
   *   The matching chapter node or null if none found.
   */
  public static function findChapterNode(\SimpleXMLElement $xml, array $possibleMatches): ?\SimpleXMLElement {
    $chapterNodes = $xml->xpath("//DIV3[@TYPE='CHAPTER']");
    if (!is_array($chapterNodes)) {
      return NULL;
    }

    foreach ($chapterNodes as $node) {
      $attrN = (string) ($node['N'] ?? '');
      $head = (string) ($node->HEAD ?? '');

      foreach ($possibleMatches as $match) {
        if ($attrN === $match || preg_match('/^\s*CHAPTER\s+' . preg_quote($match, '/') . '\b/i', $head)) {
          return $node;
        }
      }
    }

    return NULL;
  }

  /**
   * Extract plain text for all PART sections within a chapter node.
   *
   * @param \SimpleXMLElement $chapterNode
   *   The chapter node.
   * @param callable $partExtractor
   *   Callback that extracts text for a given part XML string and number.
   *
   * @return array
   *   An array of strings for each part.
   */
  public static function extractChapterPartTexts(\SimpleXMLElement $chapterNode, callable $partExtractor): array {
    $lines = [];
    $chapterHead = trim(preg_replace('/\s+/u', ' ', (string) ($chapterNode->HEAD ?? '')));
    if ($chapterHead !== '') {
      $lines[] = $chapterHead;
    }

    $partNodes = $chapterNode->xpath('.//DIV5[@TYPE="PART"]');
    if (is_array($partNodes)) {
      foreach ($partNodes as $pnode) {
        $partNum = (string) ($pnode['N'] ?? '');
        $miniXml = $pnode->asXML();
        if ($miniXml && $partNum !== '') {
          $partText = $partExtractor('<root>' . $miniXml . '</root>', $partNum);
          if ($partText !== '') {
            $lines[] = $partText;
          }
        }
      }
    }

    return $lines;
  }

  /**
   * List available title snapshots from storage.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   File system service.
   * @param string $directory
   *   Stream wrapper or path to scan.
   * @param string $pattern
   *   Regex pattern to match filenames.
   *
   * @return array
   *   Map of snapshot date => file URI.
   */
  public static function scanTitleSnapshots(FileSystemInterface $fileSystem, string $directory, string $pattern): array {
    $candidates = [];
    $files = $fileSystem->scanDirectory($directory, $pattern, ['recurse' => FALSE]);
    foreach ($files as $file) {
      if (!empty($file->filename) && preg_match($pattern, $file->filename, $matches)) {
        $candidates[$matches[1]] = $file->uri;
      }
    }
    return $candidates;
  }

  /**
   * Validate if a date string is in valid format YYYY-MM-DD).
   */
  public static function isValidDateFormat(?string $date): bool {
    if ($date === NULL) {
      return TRUE;
    }
    $date = trim($date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
  }

  /**
   * Find a subtitle node in the XML by identifier.
   */
  public static function findSubtitleNode(\SimpleXMLElement $xml, array $possibleMatches): ?\SimpleXMLElement {
    $subtitleNodes = $xml->xpath("//DIV2[@TYPE='SUBTITLE']");
    if (!is_array($subtitleNodes)) {
      return NULL;
    }

    foreach ($subtitleNodes as $node) {
      $attrN = (string) ($node['N'] ?? '');
      $head = (string) ($node->HEAD ?? '');

      foreach ($possibleMatches as $match) {
        if ($attrN === $match || preg_match('/^\s*SUBTITLE\s+' . preg_quote($match, '/') . '\b/i', $head)) {
          return $node;
        }
      }
    }

    return NULL;
  }

  /**
   * Extract plain text for all PART sections within a subtitle node.
   *
   * @param \SimpleXMLElement $subtitleNode
   *   The subtitle node.
   * @param callable $partExtractor
   *   Callback that extracts text for a given part XML string and number.
   *
   * @return array
   *   An array of strings for each part.
   */
  public static function extractSubtitlePartTexts(\SimpleXMLElement $subtitleNode, callable $partExtractor): array {
    $lines = [];
    $subtitleHead = trim(preg_replace('/\s+/u', ' ', (string) ($subtitleNode->HEAD ?? '')));
    if ($subtitleHead !== '') {
      $lines[] = $subtitleHead;
    }

    // For subtitles, extract text directly from paragraphs under the DIV2.
    $paras = $subtitleNode->xpath('.//P|.//PSPACE|.//FP|.//FP-1|.//FP-2|.//FP2|.//FP2-2|.//FP2-3|.//P-1|.//P-2|.//P-3|.//NOTE/P|.//NOTE/PSPACE');
    if (is_array($paras)) {
      foreach ($paras as $p) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $p));
        if ($text !== '') {
          $lines[] = $text;
        }
      }
    }

    // Also check for sections.
    $sectionNodes = $subtitleNode->xpath('.//DIV8[@TYPE="SECTION"]');
    if (is_array($sectionNodes)) {
      foreach ($sectionNodes as $section) {
        $secHead = trim(preg_replace('/\s+/u', ' ', (string) ($section->HEAD ?? '')));
        if ($secHead !== '') {
          $lines[] = $secHead;
        }
        $paras = $section->xpath('./P|./PSPACE|./FP|./FP-1|./FP-2|./FP2|./FP2-2|./FP2-3|./P-1|./P-2|./P-3|./NOTE/P|./NOTE/PSPACE');
        if (is_array($paras)) {
          foreach ($paras as $p) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $p));
            if ($text !== '') {
              $lines[] = $text;
            }
          }
        }
      }
    }

    return $lines;
  }

}
