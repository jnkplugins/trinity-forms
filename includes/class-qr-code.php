<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * A small QR code encoder (TF-21): byte mode, error correction level M,
 * versions 1-15 (up to 412 bytes, plenty for a page address). No dependency.
 *
 * Follows ISO/IEC 18004 the way Project Nayuki's reference encoder lays it out:
 * Reed-Solomon over GF(256) with 0x11D, the eight masks scored by the four
 * penalty rules, BCH-coded format and version information.
 */
final class QR_Code {

    /** Level M: [ec codewords per block, blocks in group 1, data cw in group 1, blocks in group 2, data cw in group 2]. */
    private const BLOCKS_M = [
        1  => [ 10, 1, 16, 0, 0 ],
        2  => [ 16, 1, 28, 0, 0 ],
        3  => [ 26, 1, 44, 0, 0 ],
        4  => [ 18, 2, 32, 0, 0 ],
        5  => [ 24, 2, 43, 0, 0 ],
        6  => [ 16, 4, 27, 0, 0 ],
        7  => [ 18, 4, 31, 0, 0 ],
        8  => [ 22, 2, 38, 2, 39 ],
        9  => [ 22, 3, 36, 2, 37 ],
        10 => [ 26, 4, 43, 1, 44 ],
        11 => [ 30, 1, 50, 4, 51 ],
        12 => [ 22, 6, 36, 2, 37 ],
        13 => [ 22, 8, 37, 1, 38 ],
        14 => [ 24, 4, 40, 5, 41 ],
        15 => [ 24, 5, 41, 5, 42 ],
    ];

    private const ALIGN = [
        1 => [], 2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ], 6 => [ 6, 34 ],
        7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ], 10 => [ 6, 28, 50 ],
        11 => [ 6, 30, 54 ], 12 => [ 6, 32, 58 ], 13 => [ 6, 34, 62 ], 14 => [ 6, 26, 46, 66 ],
        15 => [ 6, 26, 48, 70 ],
    ];

    /** @var array<int,array<int,bool>> [y][x] */
    private array $modules = [];
    /** @var array<int,array<int,bool>> */
    private array $function = [];
    private int $size = 0;
    public int $version = 0;
    public int $mask = 0;

    /**
     * @return array<int,array<int,bool>>|null rows of dark (true) / light modules, or null when too long.
     */
    public static function matrix( string $data ): ?array {
        $qr = new self();
        return $qr->encode( $data ) ? $qr->modules : null;
    }

    public static function max_bytes(): int {
        $b = self::BLOCKS_M[15];
        return (int) floor( ( ( $b[1] * $b[2] + $b[3] * $b[4] ) * 8 - 4 - 16 ) / 8 );
    }

    /** An SVG drawing of $data, or "" when it is too long. */
    public static function svg( string $data, int $px = 256, string $title = "" ): string {
        $m = self::matrix( $data );
        if ( null === $m ) {
            return "";
        }
        $n     = count( $m );
        $quiet = 4;
        $full  = $n + 2 * $quiet;
        $path  = "";
        foreach ( $m as $y => $row ) {
            $x = 0;
            while ( $x < $n ) {
                if ( ! $row[ $x ] ) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ( $x < $n && $row[ $x ] ) {
                    $x++;
                }
                $path .= "M" . ( $start + $quiet ) . " " . ( $y + $quiet ) . "h" . ( $x - $start ) . "v1h-" . ( $x - $start ) . "z";
            }
        }
        $label = "" !== $title ? '<title>' . esc_html( $title ) . '</title>' : "";
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . " " . $full . '" width="' . (int) $px . '" height="' . (int) $px . '" shape-rendering="crispEdges" role="img">'
            . $label . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /* ------------------------------------------------------------ encoding */

    private function encode( string $data ): bool {
        $len     = strlen( $data );
        $version = 0;
        foreach ( self::BLOCKS_M as $v => $b ) {
            $cap  = ( $b[1] * $b[2] + $b[3] * $b[4] ) * 8;
            $need = 4 + ( $v < 10 ? 8 : 16 ) + 8 * $len;
            if ( $need <= $cap ) {
                $version = $v;
                break;
            }
        }
        if ( ! $version ) {
            return false;
        }
        $this->version = $version;
        $b             = self::BLOCKS_M[ $version ];
        $data_cw       = $b[1] * $b[2] + $b[3] * $b[4];

        // Bit stream: mode 0100, count, bytes, terminator, pad.
        $bits = [];
        $push = static function ( int $value, int $count ) use ( &$bits ): void {
            for ( $i = $count - 1; $i >= 0; $i-- ) {
                $bits[] = ( $value >> $i ) & 1;
            }
        };
        $push( 4, 4 );
        $push( $len, $version < 10 ? 8 : 16 );
        for ( $i = 0; $i < $len; $i++ ) {
            $push( ord( $data[ $i ] ), 8 );
        }
        $cap = $data_cw * 8;
        $push( 0, min( 4, $cap - count( $bits ) ) );
        $push( 0, ( 8 - count( $bits ) % 8 ) % 8 );
        for ( $pad = 0xEC; count( $bits ) < $cap; $pad ^= 0xEC ^ 0x11 ) {
            $push( $pad, 8 );
        }
        $bytes = [];
        foreach ( array_chunk( $bits, 8 ) as $chunk ) {
            $byte = 0;
            foreach ( $chunk as $bit ) {
                $byte = ( $byte << 1 ) | $bit;
            }
            $bytes[] = $byte;
        }

        // Blocks, error correction, interleave.
        $divisor = self::rs_divisor( $b[0] );
        $blocks  = [];
        $ecs     = [];
        $k       = 0;
        foreach ( [ [ $b[1], $b[2] ], [ $b[3], $b[4] ] ] as [ $count, $size ] ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $block    = array_slice( $bytes, $k, $size );
                $k       += $size;
                $blocks[] = $block;
                $ecs[]    = self::rs_remainder( $block, $divisor );
            }
        }
        $final = [];
        $max   = max( array_map( "count", $blocks ) );
        for ( $i = 0; $i < $max; $i++ ) {
            foreach ( $blocks as $block ) {
                if ( $i < count( $block ) ) {
                    $final[] = $block[ $i ];
                }
            }
        }
        for ( $i = 0; $i < $b[0]; $i++ ) {
            foreach ( $ecs as $ec ) {
                $final[] = $ec[ $i ];
            }
        }

        // Matrix.
        $this->size     = 17 + 4 * $version;
        $this->modules  = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
        $this->function = $this->modules;
        $this->draw_function_patterns();
        $this->draw_codewords( $final );

        $best = -1;
        $low  = PHP_INT_MAX;
        for ( $m = 0; $m < 8; $m++ ) {
            $this->apply_mask( $m );
            $this->draw_format( $m );
            $p = $this->penalty();
            if ( $p < $low ) {
                $low  = $p;
                $best = $m;
            }
            $this->apply_mask( $m ); // XOR again undoes it.
        }
        $this->mask = $best;
        $this->apply_mask( $best );
        $this->draw_format( $best );
        return true;
    }

    private function set( int $x, int $y, bool $dark ): void {
        $this->modules[ $y ][ $x ]  = $dark;
        $this->function[ $y ][ $x ] = true;
    }

    private function draw_function_patterns(): void {
        $n = $this->size;
        for ( $i = 0; $i < $n; $i++ ) {
            $this->set( 6, $i, 0 === $i % 2 );
            $this->set( $i, 6, 0 === $i % 2 );
        }
        foreach ( [ [ 3, 3 ], [ $n - 4, 3 ], [ 3, $n - 4 ] ] as [ $cx, $cy ] ) {
            for ( $dy = -4; $dy <= 4; $dy++ ) {
                for ( $dx = -4; $dx <= 4; $dx++ ) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ( $x >= 0 && $x < $n && $y >= 0 && $y < $n ) {
                        $dist = max( abs( $dx ), abs( $dy ) );
                        $this->set( $x, $y, 2 !== $dist && 4 !== $dist );
                    }
                }
            }
        }
        $pos = self::ALIGN[ $this->version ];
        $num = count( $pos );
        for ( $i = 0; $i < $num; $i++ ) {
            for ( $j = 0; $j < $num; $j++ ) {
                if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $num - 1 === $j ) || ( $num - 1 === $i && 0 === $j ) ) {
                    continue; // Finder corners.
                }
                for ( $dy = -2; $dy <= 2; $dy++ ) {
                    for ( $dx = -2; $dx <= 2; $dx++ ) {
                        $this->set( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
                    }
                }
            }
        }
        $this->draw_format( 0 ); // Reserve the areas; redrawn per mask.
        if ( $this->version >= 7 ) {
            $rem = $this->version;
            for ( $i = 0; $i < 12; $i++ ) {
                $rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
            }
            $bits = ( $this->version << 12 ) | $rem;
            for ( $i = 0; $i < 18; $i++ ) {
                $bit = 1 === ( ( $bits >> $i ) & 1 );
                $a   = $n - 11 + $i % 3;
                $b   = intdiv( $i, 3 );
                $this->set( $a, $b, $bit );
                $this->set( $b, $a, $bit );
            }
        }
    }

    private function draw_format( int $mask ): void {
        $data = ( 0 << 3 ) | $mask; // Level M = 00.
        $rem  = $data;
        for ( $i = 0; $i < 10; $i++ ) {
            $rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
        }
        $bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
        $bit  = static fn( int $i ): bool => 1 === ( ( $bits >> $i ) & 1 );
        $n    = $this->size;
        for ( $i = 0; $i <= 5; $i++ ) {
            $this->set( 8, $i, $bit( $i ) );
        }
        $this->set( 8, 7, $bit( 6 ) );
        $this->set( 8, 8, $bit( 7 ) );
        $this->set( 7, 8, $bit( 8 ) );
        for ( $i = 9; $i < 15; $i++ ) {
            $this->set( 14 - $i, 8, $bit( $i ) );
        }
        for ( $i = 0; $i < 8; $i++ ) {
            $this->set( $n - 1 - $i, 8, $bit( $i ) );
        }
        for ( $i = 8; $i < 15; $i++ ) {
            $this->set( 8, $n - 15 + $i, $bit( $i ) );
        }
        $this->set( 8, $n - 8, true ); // Dark module.
    }

    /** @param int[] $data */
    private function draw_codewords( array $data ): void {
        $n     = $this->size;
        $i     = 0;
        $total = count( $data ) * 8;
        for ( $right = $n - 1; $right >= 1; $right -= 2 ) {
            if ( 6 === $right ) {
                $right = 5;
            }
            for ( $vert = 0; $vert < $n; $vert++ ) {
                for ( $j = 0; $j < 2; $j++ ) {
                    $x      = $right - $j;
                    $upward = 0 === ( ( $right + 1 ) & 2 );
                    $y      = $upward ? $n - 1 - $vert : $vert;
                    if ( ! $this->function[ $y ][ $x ] && $i < $total ) {
                        $this->modules[ $y ][ $x ] = 1 === ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
                        $i++;
                    }
                }
            }
        }
    }

    private function apply_mask( int $mask ): void {
        $n = $this->size;
        for ( $y = 0; $y < $n; $y++ ) {
            for ( $x = 0; $x < $n; $x++ ) {
                if ( $this->function[ $y ][ $x ] ) {
                    continue;
                }
                switch ( $mask ) {
                    case 0: $flip = 0 === ( $x + $y ) % 2; break;
                    case 1: $flip = 0 === $y % 2; break;
                    case 2: $flip = 0 === $x % 3; break;
                    case 3: $flip = 0 === ( $x + $y ) % 3; break;
                    case 4: $flip = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2; break;
                    case 5: $flip = 0 === $x * $y % 2 + $x * $y % 3; break;
                    case 6: $flip = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2; break;
                    default: $flip = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
                }
                if ( $flip ) {
                    $this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
                }
            }
        }
    }

    private function penalty(): int {
        $n     = $this->size;
        $score = 0;
        $lines = [];
        for ( $i = 0; $i < $n; $i++ ) {
            $lines[] = $this->modules[ $i ];
            $lines[] = array_column( $this->modules, $i );
        }
        foreach ( $lines as $line ) {
            // Rule 1: runs of five or more.
            $run = 1;
            for ( $i = 1; $i <= $n; $i++ ) {
                if ( $i < $n && $line[ $i ] === $line[ $i - 1 ] ) {
                    $run++;
                    continue;
                }
                if ( $run >= 5 ) {
                    $score += $run - 2;
                }
                $run = 1;
            }
            // Rule 3: finder-like 1:1:3:1:1 with four light modules on a side.
            $s = implode( "", array_map( static fn( $b ) => $b ? "1" : "0", $line ) );
            $score += 40 * ( substr_count( "0000" . $s, "00001011101" ) + substr_count( $s . "0000", "10111010000" ) );
        }
        // Rule 2: 2x2 blocks.
        $dark = 0;
        for ( $y = 0; $y < $n; $y++ ) {
            for ( $x = 0; $x < $n; $x++ ) {
                $c     = $this->modules[ $y ][ $x ];
                $dark += $c ? 1 : 0;
                if ( $x < $n - 1 && $y < $n - 1 && $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
                    $score += 3;
                }
            }
        }
        // Rule 4: balance of dark and light.
        $total  = $n * $n;
        $score += 10 * intdiv( abs( $dark * 20 - $total * 10 ) + $total - 1, $total ) - 10;
        return $score;
    }

    /* ------------------------------------------------------------ Reed-Solomon */

    private static function gf_mul( int $x, int $y ): int {
        $z = 0;
        for ( $i = 7; $i >= 0; $i-- ) {
            $z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
            $z ^= ( ( $y >> $i ) & 1 ) * $x;
        }
        return $z & 0xFF;
    }

    /** @return int[] */
    private static function rs_divisor( int $degree ): array {
        $result               = array_fill( 0, $degree, 0 );
        $result[ $degree - 1 ] = 1;
        $root                 = 1;
        for ( $i = 0; $i < $degree; $i++ ) {
            for ( $j = 0; $j < $degree; $j++ ) {
                $result[ $j ] = self::gf_mul( $result[ $j ], $root );
                if ( $j + 1 < $degree ) {
                    $result[ $j ] ^= $result[ $j + 1 ];
                }
            }
            $root = self::gf_mul( $root, 0x02 );
        }
        return $result;
    }

    /** @param int[] $data @param int[] $divisor @return int[] */
    private static function rs_remainder( array $data, array $divisor ): array {
        $result = array_fill( 0, count( $divisor ), 0 );
        foreach ( $data as $b ) {
            $factor = $b ^ array_shift( $result );
            $result[] = 0;
            foreach ( $divisor as $i => $coef ) {
                $result[ $i ] ^= self::gf_mul( $coef, $factor );
            }
        }
        return $result;
    }
}
