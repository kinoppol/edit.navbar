<?php
declare(strict_types=1);

/**
 * Minimal pure-PHP ZIP reader/writer.
 *
 * Works without the `zip` / ZipArchive extension — it only needs zlib's
 * gzdeflate/gzinflate (raw DEFLATE, ZIP method 8) plus crc32, all of which
 * are part of the always-on zlib extension on this install.
 *
 * Files are passed/returned as an associative array: ['path/in/zip' => binary].
 */

/**
 * Build a ZIP archive in memory and return its raw bytes.
 * Each entry is DEFLATE-compressed unless that would grow it (then stored).
 *
 * @param array<string,string> $files
 */
function rvc_zip_create(array $files): string
{
    $local   = '';
    $central = '';
    $offset  = 0;
    // Fixed valid DOS timestamp: 1980-01-01 00:00:00
    $dosTime = 0;
    $dosDate = 0x0021;

    foreach ($files as $name => $content) {
        $name    = str_replace('\\', '/', (string) $name);
        $content = (string) $content;
        $crc     = crc32($content);
        $unc     = strlen($content);

        $deflated = gzdeflate($content, 6);
        if ($deflated === false || strlen($deflated) >= $unc) {
            $method = 0;            // store
            $data   = $content;
        } else {
            $method = 8;            // deflate
            $data   = $deflated;
        }
        $comp    = strlen($data);
        $nameLen = strlen($name);

        $localHeader =
              pack('V', 0x04034b50)   // local file header signature
            . pack('v', 20)           // version needed to extract
            . pack('v', 0)            // general purpose flags
            . pack('v', $method)      // compression method
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $comp)
            . pack('V', $unc)
            . pack('v', $nameLen)
            . pack('v', 0)            // extra field length
            . $name;

        $local .= $localHeader . $data;

        $central .=
              pack('V', 0x02014b50)   // central directory header signature
            . pack('v', 20)           // version made by
            . pack('v', 20)           // version needed
            . pack('v', 0)            // flags
            . pack('v', $method)
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $comp)
            . pack('V', $unc)
            . pack('v', $nameLen)
            . pack('v', 0)            // extra length
            . pack('v', 0)            // comment length
            . pack('v', 0)            // disk number start
            . pack('v', 0)            // internal attributes
            . pack('V', 0)            // external attributes
            . pack('V', $offset)      // offset of local header
            . $name;

        $offset += strlen($localHeader) + strlen($data);
    }

    $count    = count($files);
    $cdSize   = strlen($central);
    $cdOffset = $offset;

    $eocd =
          pack('V', 0x06054b50)       // end of central directory signature
        . pack('v', 0)                // number of this disk
        . pack('v', 0)                // disk with central directory
        . pack('v', $count)           // entries on this disk
        . pack('v', $count)           // total entries
        . pack('V', $cdSize)
        . pack('V', $cdOffset)
        . pack('v', 0);               // comment length

    return $local . $central . $eocd;
}

/**
 * Parse a ZIP archive (via its central directory) and return its entries.
 * Supports stored (0) and deflated (8) entries.
 *
 * @return array<string,string>
 * @throws \RuntimeException on malformed input or unsupported compression
 */
function rvc_zip_read(string $bin): array
{
    $eocdPos = strrpos($bin, "\x50\x4b\x05\x06");
    if ($eocdPos === false) {
        throw new \RuntimeException('ไฟล์ ZIP ไม่ถูกต้อง (ไม่พบ End of Central Directory)');
    }
    $eocd = unpack(
        'vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoffset/vcomment',
        substr($bin, $eocdPos + 4, 18)
    );
    $count = (int) $eocd['total'];
    $p     = (int) $eocd['cdoffset'];

    $files = [];
    for ($i = 0; $i < $count; $i++) {
        if (substr($bin, $p, 4) !== "\x50\x4b\x01\x02") {
            break;
        }
        $h = unpack(
            'vver/vverneed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcomp/Vunc/'
            . 'vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Vlocaloff',
            substr($bin, $p + 4, 42)
        );
        $nameLen = (int) $h['namelen'];
        $name    = substr($bin, $p + 46, $nameLen);
        $localOff = (int) $h['localoff'];

        // Read the local header to skip its (possibly different) name/extra fields.
        $lh = unpack('vnamelen/vextralen', substr($bin, $localOff + 26, 4));
        $dataStart = $localOff + 30 + (int) $lh['namelen'] + (int) $lh['extralen'];
        $comp      = substr($bin, $dataStart, (int) $h['comp']);

        $method = (int) $h['method'];
        if ($method === 8) {
            $content = gzinflate($comp);
        } elseif ($method === 0) {
            $content = $comp;
        } else {
            throw new \RuntimeException('ZIP ใช้วิธีบีบอัดที่ไม่รองรับ (method ' . $method . ')');
        }
        if ($content === false) {
            throw new \RuntimeException('ไม่สามารถคลายไฟล์ "' . $name . '" ใน ZIP');
        }

        $files[str_replace('\\', '/', $name)] = $content;
        $p += 46 + $nameLen + (int) $h['extralen'] + (int) $h['commentlen'];
    }

    return $files;
}
