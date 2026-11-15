//! `pow()` as musl computes it (`src/math/pow.c`, from Arm's optimized
//! routines, MIT licensed): PHP's `**` on floats calls the C library's `pow`,
//! and the dev image is Alpine. This is not correctly rounded (the worst case
//! is 0.54 ULP), so a correctly rounded or platform `pow` differs from PHP's in
//! the last digit for some inputs. The fused multiply-add paths are the ones
//! musl takes where the target has FMA (`__FP_FAST_FMA`, aarch64).

// The tables and constants are musl's, bit for bit, and so is the order of
// every floating-point operation.
#![allow(clippy::unreadable_literal, clippy::excessive_precision, clippy::assign_op_pattern)]

const LN2HI: f64 = f64::from_bits(0x3fe62e42fefa3800);
const LN2LO: f64 = f64::from_bits(0x3d2ef35793c76730);
const OFF: u64 = 0x3fe6955500000000;
const TABLE_BITS: u32 = 7;

/// `-0.5` then the log polynomial, scaled as musl evaluates it.
const A: [f64; 7] = [
    f64::from_bits(0xbfe0000000000000),
    f64::from_bits(0xbfe5555555555560),
    f64::from_bits(0x3fe0000000000006),
    f64::from_bits(0x3fe999999959554e),
    f64::from_bits(0xbfe555555529a47a),
    f64::from_bits(0xbff2495b9b4845e9),
    f64::from_bits(0x3ff0002b8b263fc3),
];

/// `(invc, logc, logctail)` of each of the 128 subintervals.
const T: [(f64, f64, f64); 128] = [
    (f64::from_bits(0x3ff6a00000000000), f64::from_bits(0xbfd62c82f2b9c800), f64::from_bits(0x3cfab42428375680)),
    (f64::from_bits(0x3ff6800000000000), f64::from_bits(0xbfd5d1bdbf580800), f64::from_bits(0xbd1ca508d8e0f720)),
    (f64::from_bits(0x3ff6600000000000), f64::from_bits(0xbfd5767717455800), f64::from_bits(0xbd2362a4d5b6506d)),
    (f64::from_bits(0x3ff6400000000000), f64::from_bits(0xbfd51aad872df800), f64::from_bits(0xbce684e49eb067d5)),
    (f64::from_bits(0x3ff6200000000000), f64::from_bits(0xbfd4be5f95777800), f64::from_bits(0xbd041b6993293ee0)),
    (f64::from_bits(0x3ff6000000000000), f64::from_bits(0xbfd4618bc21c6000), f64::from_bits(0x3d13d82f484c84cc)),
    (f64::from_bits(0x3ff5e00000000000), f64::from_bits(0xbfd404308686a800), f64::from_bits(0x3cdc42f3ed820b3a)),
    (f64::from_bits(0x3ff5c00000000000), f64::from_bits(0xbfd3a64c55694800), f64::from_bits(0x3d20b1c686519460)),
    (f64::from_bits(0x3ff5a00000000000), f64::from_bits(0xbfd347dd9a988000), f64::from_bits(0x3d25594dd4c58092)),
    (f64::from_bits(0x3ff5800000000000), f64::from_bits(0xbfd2e8e2bae12000), f64::from_bits(0x3d267b1e99b72bd8)),
    (f64::from_bits(0x3ff5600000000000), f64::from_bits(0xbfd2895a13de8800), f64::from_bits(0x3d15ca14b6cfb03f)),
    (f64::from_bits(0x3ff5600000000000), f64::from_bits(0xbfd2895a13de8800), f64::from_bits(0x3d15ca14b6cfb03f)),
    (f64::from_bits(0x3ff5400000000000), f64::from_bits(0xbfd22941fbcf7800), f64::from_bits(0xbd165a242853da76)),
    (f64::from_bits(0x3ff5200000000000), f64::from_bits(0xbfd1c898c1699800), f64::from_bits(0xbd1fafbc68e75404)),
    (f64::from_bits(0x3ff5000000000000), f64::from_bits(0xbfd1675cababa800), f64::from_bits(0x3d1f1fc63382a8f0)),
    (f64::from_bits(0x3ff4e00000000000), f64::from_bits(0xbfd1058bf9ae4800), f64::from_bits(0xbd26a8c4fd055a66)),
    (f64::from_bits(0x3ff4c00000000000), f64::from_bits(0xbfd0a324e2739000), f64::from_bits(0xbd0c6bee7ef4030e)),
    (f64::from_bits(0x3ff4a00000000000), f64::from_bits(0xbfd0402594b4d000), f64::from_bits(0xbcf036b89ef42d7f)),
    (f64::from_bits(0x3ff4a00000000000), f64::from_bits(0xbfd0402594b4d000), f64::from_bits(0xbcf036b89ef42d7f)),
    (f64::from_bits(0x3ff4800000000000), f64::from_bits(0xbfcfb9186d5e4000), f64::from_bits(0x3d0d572aab993c87)),
    (f64::from_bits(0x3ff4600000000000), f64::from_bits(0xbfcef0adcbdc6000), f64::from_bits(0x3d2b26b79c86af24)),
    (f64::from_bits(0x3ff4400000000000), f64::from_bits(0xbfce27076e2af000), f64::from_bits(0xbd172f4f543fff10)),
    (f64::from_bits(0x3ff4200000000000), f64::from_bits(0xbfcd5c216b4fc000), f64::from_bits(0x3d21ba91bbca681b)),
    (f64::from_bits(0x3ff4000000000000), f64::from_bits(0xbfcc8ff7c79aa000), f64::from_bits(0x3d27794f689f8434)),
    (f64::from_bits(0x3ff4000000000000), f64::from_bits(0xbfcc8ff7c79aa000), f64::from_bits(0x3d27794f689f8434)),
    (f64::from_bits(0x3ff3e00000000000), f64::from_bits(0xbfcbc286742d9000), f64::from_bits(0x3d194eb0318bb78f)),
    (f64::from_bits(0x3ff3c00000000000), f64::from_bits(0xbfcaf3c94e80c000), f64::from_bits(0x3cba4e633fcd9066)),
    (f64::from_bits(0x3ff3a00000000000), f64::from_bits(0xbfca23bc1fe2b000), f64::from_bits(0xbd258c64dc46c1ea)),
    (f64::from_bits(0x3ff3a00000000000), f64::from_bits(0xbfca23bc1fe2b000), f64::from_bits(0xbd258c64dc46c1ea)),
    (f64::from_bits(0x3ff3800000000000), f64::from_bits(0xbfc9525a9cf45000), f64::from_bits(0xbd2ad1d904c1d4e3)),
    (f64::from_bits(0x3ff3600000000000), f64::from_bits(0xbfc87fa06520d000), f64::from_bits(0x3d2bbdbf7fdbfa09)),
    (f64::from_bits(0x3ff3400000000000), f64::from_bits(0xbfc7ab890210e000), f64::from_bits(0x3d2bdb9072534a58)),
    (f64::from_bits(0x3ff3400000000000), f64::from_bits(0xbfc7ab890210e000), f64::from_bits(0x3d2bdb9072534a58)),
    (f64::from_bits(0x3ff3200000000000), f64::from_bits(0xbfc6d60fe719d000), f64::from_bits(0xbd10e46aa3b2e266)),
    (f64::from_bits(0x3ff3000000000000), f64::from_bits(0xbfc5ff3070a79000), f64::from_bits(0xbd1e9e439f105039)),
    (f64::from_bits(0x3ff3000000000000), f64::from_bits(0xbfc5ff3070a79000), f64::from_bits(0xbd1e9e439f105039)),
    (f64::from_bits(0x3ff2e00000000000), f64::from_bits(0xbfc526e5e3a1b000), f64::from_bits(0xbd20de8b90075b8f)),
    (f64::from_bits(0x3ff2c00000000000), f64::from_bits(0xbfc44d2b6ccb8000), f64::from_bits(0x3d170cc16135783c)),
    (f64::from_bits(0x3ff2c00000000000), f64::from_bits(0xbfc44d2b6ccb8000), f64::from_bits(0x3d170cc16135783c)),
    (f64::from_bits(0x3ff2a00000000000), f64::from_bits(0xbfc371fc201e9000), f64::from_bits(0x3cf178864d27543a)),
    (f64::from_bits(0x3ff2800000000000), f64::from_bits(0xbfc29552f81ff000), f64::from_bits(0xbd248d301771c408)),
    (f64::from_bits(0x3ff2600000000000), f64::from_bits(0xbfc1b72ad52f6000), f64::from_bits(0xbd2e80a41811a396)),
    (f64::from_bits(0x3ff2600000000000), f64::from_bits(0xbfc1b72ad52f6000), f64::from_bits(0xbd2e80a41811a396)),
    (f64::from_bits(0x3ff2400000000000), f64::from_bits(0xbfc0d77e7cd09000), f64::from_bits(0x3d0a699688e85bf4)),
    (f64::from_bits(0x3ff2400000000000), f64::from_bits(0xbfc0d77e7cd09000), f64::from_bits(0x3d0a699688e85bf4)),
    (f64::from_bits(0x3ff2200000000000), f64::from_bits(0xbfbfec9131dbe000), f64::from_bits(0xbd2575545ca333f2)),
    (f64::from_bits(0x3ff2000000000000), f64::from_bits(0xbfbe27076e2b0000), f64::from_bits(0x3d2a342c2af0003c)),
    (f64::from_bits(0x3ff2000000000000), f64::from_bits(0xbfbe27076e2b0000), f64::from_bits(0x3d2a342c2af0003c)),
    (f64::from_bits(0x3ff1e00000000000), f64::from_bits(0xbfbc5e548f5bc000), f64::from_bits(0xbd1d0c57585fbe06)),
    (f64::from_bits(0x3ff1c00000000000), f64::from_bits(0xbfba926d3a4ae000), f64::from_bits(0x3d253935e85baac8)),
    (f64::from_bits(0x3ff1c00000000000), f64::from_bits(0xbfba926d3a4ae000), f64::from_bits(0x3d253935e85baac8)),
    (f64::from_bits(0x3ff1a00000000000), f64::from_bits(0xbfb8c345d631a000), f64::from_bits(0x3d137c294d2f5668)),
    (f64::from_bits(0x3ff1a00000000000), f64::from_bits(0xbfb8c345d631a000), f64::from_bits(0x3d137c294d2f5668)),
    (f64::from_bits(0x3ff1800000000000), f64::from_bits(0xbfb6f0d28ae56000), f64::from_bits(0xbd269737c93373da)),
    (f64::from_bits(0x3ff1600000000000), f64::from_bits(0xbfb51b073f062000), f64::from_bits(0x3d1f025b61c65e57)),
    (f64::from_bits(0x3ff1600000000000), f64::from_bits(0xbfb51b073f062000), f64::from_bits(0x3d1f025b61c65e57)),
    (f64::from_bits(0x3ff1400000000000), f64::from_bits(0xbfb341d7961be000), f64::from_bits(0x3d2c5edaccf913df)),
    (f64::from_bits(0x3ff1400000000000), f64::from_bits(0xbfb341d7961be000), f64::from_bits(0x3d2c5edaccf913df)),
    (f64::from_bits(0x3ff1200000000000), f64::from_bits(0xbfb16536eea38000), f64::from_bits(0x3d147c5e768fa309)),
    (f64::from_bits(0x3ff1000000000000), f64::from_bits(0xbfaf0a30c0118000), f64::from_bits(0x3d2d599e83368e91)),
    (f64::from_bits(0x3ff1000000000000), f64::from_bits(0xbfaf0a30c0118000), f64::from_bits(0x3d2d599e83368e91)),
    (f64::from_bits(0x3ff0e00000000000), f64::from_bits(0xbfab42dd71198000), f64::from_bits(0x3d1c827ae5d6704c)),
    (f64::from_bits(0x3ff0e00000000000), f64::from_bits(0xbfab42dd71198000), f64::from_bits(0x3d1c827ae5d6704c)),
    (f64::from_bits(0x3ff0c00000000000), f64::from_bits(0xbfa77458f632c000), f64::from_bits(0xbd2cfc4634f2a1ee)),
    (f64::from_bits(0x3ff0c00000000000), f64::from_bits(0xbfa77458f632c000), f64::from_bits(0xbd2cfc4634f2a1ee)),
    (f64::from_bits(0x3ff0a00000000000), f64::from_bits(0xbfa39e87b9fec000), f64::from_bits(0x3cf502b7f526feaa)),
    (f64::from_bits(0x3ff0a00000000000), f64::from_bits(0xbfa39e87b9fec000), f64::from_bits(0x3cf502b7f526feaa)),
    (f64::from_bits(0x3ff0800000000000), f64::from_bits(0xbf9f829b0e780000), f64::from_bits(0xbd2980267c7e09e4)),
    (f64::from_bits(0x3ff0800000000000), f64::from_bits(0xbf9f829b0e780000), f64::from_bits(0xbd2980267c7e09e4)),
    (f64::from_bits(0x3ff0600000000000), f64::from_bits(0xbf97b91b07d58000), f64::from_bits(0xbd288d5493faa639)),
    (f64::from_bits(0x3ff0400000000000), f64::from_bits(0xbf8fc0a8b0fc0000), f64::from_bits(0xbcdf1e7cf6d3a69c)),
    (f64::from_bits(0x3ff0400000000000), f64::from_bits(0xbf8fc0a8b0fc0000), f64::from_bits(0xbcdf1e7cf6d3a69c)),
    (f64::from_bits(0x3ff0200000000000), f64::from_bits(0xbf7fe02a6b100000), f64::from_bits(0xbd19e23f0dda40e4)),
    (f64::from_bits(0x3ff0200000000000), f64::from_bits(0xbf7fe02a6b100000), f64::from_bits(0xbd19e23f0dda40e4)),
    (f64::from_bits(0x3ff0000000000000), f64::from_bits(0x0000000000000000), f64::from_bits(0x0000000000000000)),
    (f64::from_bits(0x3ff0000000000000), f64::from_bits(0x0000000000000000), f64::from_bits(0x0000000000000000)),
    (f64::from_bits(0x3fefc00000000000), f64::from_bits(0x3f80101575890000), f64::from_bits(0xbd10c76b999d2be8)),
    (f64::from_bits(0x3fef800000000000), f64::from_bits(0x3f90205658938000), f64::from_bits(0xbd23dc5b06e2f7d2)),
    (f64::from_bits(0x3fef400000000000), f64::from_bits(0x3f98492528c90000), f64::from_bits(0xbd2aa0ba325a0c34)),
    (f64::from_bits(0x3fef000000000000), f64::from_bits(0x3fa0415d89e74000), f64::from_bits(0x3d0111c05cf1d753)),
    (f64::from_bits(0x3feec00000000000), f64::from_bits(0x3fa466aed42e0000), f64::from_bits(0xbd2c167375bdfd28)),
    (f64::from_bits(0x3fee800000000000), f64::from_bits(0x3fa894aa149fc000), f64::from_bits(0xbd197995d05a267d)),
    (f64::from_bits(0x3fee400000000000), f64::from_bits(0x3faccb73cdddc000), f64::from_bits(0xbd1a68f247d82807)),
    (f64::from_bits(0x3fee200000000000), f64::from_bits(0x3faeea31c006c000), f64::from_bits(0xbd0e113e4fc93b7b)),
    (f64::from_bits(0x3fede00000000000), f64::from_bits(0x3fb1973bd1466000), f64::from_bits(0xbd25325d560d9e9b)),
    (f64::from_bits(0x3feda00000000000), f64::from_bits(0x3fb3bdf5a7d1e000), f64::from_bits(0x3d2cc85ea5db4ed7)),
    (f64::from_bits(0x3fed600000000000), f64::from_bits(0x3fb5e95a4d97a000), f64::from_bits(0xbd2c69063c5d1d1e)),
    (f64::from_bits(0x3fed400000000000), f64::from_bits(0x3fb700d30aeac000), f64::from_bits(0x3cec1e8da99ded32)),
    (f64::from_bits(0x3fed000000000000), f64::from_bits(0x3fb9335e5d594000), f64::from_bits(0x3d23115c3abd47da)),
    (f64::from_bits(0x3fecc00000000000), f64::from_bits(0x3fbb6ac88dad6000), f64::from_bits(0xbd1390802bf768e5)),
    (f64::from_bits(0x3feca00000000000), f64::from_bits(0x3fbc885801bc4000), f64::from_bits(0x3d2646d1c65aacd3)),
    (f64::from_bits(0x3fec600000000000), f64::from_bits(0x3fbec739830a2000), f64::from_bits(0xbd2dc068afe645e0)),
    (f64::from_bits(0x3fec400000000000), f64::from_bits(0x3fbfe89139dbe000), f64::from_bits(0xbd2534d64fa10afd)),
    (f64::from_bits(0x3fec000000000000), f64::from_bits(0x3fc1178e8227e000), f64::from_bits(0x3d21ef78ce2d07f2)),
    (f64::from_bits(0x3febe00000000000), f64::from_bits(0x3fc1aa2b7e23f000), f64::from_bits(0x3d2ca78e44389934)),
    (f64::from_bits(0x3feba00000000000), f64::from_bits(0x3fc2d1610c868000), f64::from_bits(0x3d039d6ccb81b4a1)),
    (f64::from_bits(0x3feb800000000000), f64::from_bits(0x3fc365fcb0159000), f64::from_bits(0x3cc62fa8234b7289)),
    (f64::from_bits(0x3feb400000000000), f64::from_bits(0x3fc4913d8333b000), f64::from_bits(0x3d25837954fdb678)),
    (f64::from_bits(0x3feb200000000000), f64::from_bits(0x3fc527e5e4a1b000), f64::from_bits(0x3d2633e8e5697dc7)),
    (f64::from_bits(0x3feae00000000000), f64::from_bits(0x3fc6574ebe8c1000), f64::from_bits(0x3d19cf8b2c3c2e78)),
    (f64::from_bits(0x3feac00000000000), f64::from_bits(0x3fc6f0128b757000), f64::from_bits(0xbd25118de59c21e1)),
    (f64::from_bits(0x3feaa00000000000), f64::from_bits(0x3fc7898d85445000), f64::from_bits(0xbd1c661070914305)),
    (f64::from_bits(0x3fea600000000000), f64::from_bits(0x3fc8beafeb390000), f64::from_bits(0xbd073d54aae92cd1)),
    (f64::from_bits(0x3fea400000000000), f64::from_bits(0x3fc95a5adcf70000), f64::from_bits(0x3d07f22858a0ff6f)),
    (f64::from_bits(0x3fea000000000000), f64::from_bits(0x3fca93ed3c8ae000), f64::from_bits(0xbd28724350562169)),
    (f64::from_bits(0x3fe9e00000000000), f64::from_bits(0x3fcb31d8575bd000), f64::from_bits(0xbd0c358d4eace1aa)),
    (f64::from_bits(0x3fe9c00000000000), f64::from_bits(0x3fcbd087383be000), f64::from_bits(0xbd2d4bc4595412b6)),
    (f64::from_bits(0x3fe9a00000000000), f64::from_bits(0x3fcc6ffbc6f01000), f64::from_bits(0xbcf1ec72c5962bd2)),
    (f64::from_bits(0x3fe9600000000000), f64::from_bits(0x3fcdb13db0d49000), f64::from_bits(0xbd2aff2af715b035)),
    (f64::from_bits(0x3fe9400000000000), f64::from_bits(0x3fce530effe71000), f64::from_bits(0x3cc212276041f430)),
    (f64::from_bits(0x3fe9200000000000), f64::from_bits(0x3fcef5ade4dd0000), f64::from_bits(0xbcca211565bb8e11)),
    (f64::from_bits(0x3fe9000000000000), f64::from_bits(0x3fcf991c6cb3b000), f64::from_bits(0x3d1bcbecca0cdf30)),
    (f64::from_bits(0x3fe8c00000000000), f64::from_bits(0x3fd07138604d5800), f64::from_bits(0x3cf89cdb16ed4e91)),
    (f64::from_bits(0x3fe8a00000000000), f64::from_bits(0x3fd0c42d67616000), f64::from_bits(0x3d27188b163ceae9)),
    (f64::from_bits(0x3fe8800000000000), f64::from_bits(0x3fd1178e8227e800), f64::from_bits(0xbd2c210e63a5f01c)),
    (f64::from_bits(0x3fe8600000000000), f64::from_bits(0x3fd16b5ccbacf800), f64::from_bits(0x3d2b9acdf7a51681)),
    (f64::from_bits(0x3fe8400000000000), f64::from_bits(0x3fd1bf99635a6800), f64::from_bits(0x3d2ca6ed5147bdb7)),
    (f64::from_bits(0x3fe8200000000000), f64::from_bits(0x3fd214456d0eb800), f64::from_bits(0x3d0a87deba46baea)),
    (f64::from_bits(0x3fe7e00000000000), f64::from_bits(0x3fd2bef07cdc9000), f64::from_bits(0x3d2a9cfa4a5004f4)),
    (f64::from_bits(0x3fe7c00000000000), f64::from_bits(0x3fd314f1e1d36000), f64::from_bits(0xbd28e27ad3213cb8)),
    (f64::from_bits(0x3fe7a00000000000), f64::from_bits(0x3fd36b6776be1000), f64::from_bits(0x3d116ecdb0f177c8)),
    (f64::from_bits(0x3fe7800000000000), f64::from_bits(0x3fd3c25277333000), f64::from_bits(0x3d183b54b606bd5c)),
    (f64::from_bits(0x3fe7600000000000), f64::from_bits(0x3fd419b423d5e800), f64::from_bits(0x3d08e436ec90e09d)),
    (f64::from_bits(0x3fe7400000000000), f64::from_bits(0x3fd4718dc271c800), f64::from_bits(0xbd2f27ce0967d675)),
    (f64::from_bits(0x3fe7200000000000), f64::from_bits(0x3fd4c9e09e173000), f64::from_bits(0xbd2e20891b0ad8a4)),
    (f64::from_bits(0x3fe7000000000000), f64::from_bits(0x3fd522ae0738a000), f64::from_bits(0x3d2ebe708164c759)),
    (f64::from_bits(0x3fe6e00000000000), f64::from_bits(0x3fd57bf753c8d000), f64::from_bits(0x3d1fadedee5d40ef)),
    (f64::from_bits(0x3fe6c00000000000), f64::from_bits(0x3fd5d5bddf596000), f64::from_bits(0xbd0a0b2a08a465dc)),
];

const INV_LN2_N: f64 = f64::from_bits(0x40671547652b82fe);
const NEG_LN2_HI_N: f64 = f64::from_bits(0xbf762e42fefa0000);
const NEG_LN2_LO_N: f64 = f64::from_bits(0xbd0cf79abc9e3b3a);
const SHIFT: f64 = f64::from_bits(0x4338000000000000);
const C2: f64 = f64::from_bits(0x3fdffffffffffdbd);
const C3: f64 = f64::from_bits(0x3fc555555555543c);
const C4: f64 = f64::from_bits(0x3fa55555cf172b91);
const C5: f64 = f64::from_bits(0x3f81111167a4d017);
const SIGN_BIAS: u64 = 0x800 << 7;

/// `2^(k/N) ~= H[k] * (1 + T[k])`: `tab[2k]` is `T[k]`'s bits, `tab[2k+1]` is
/// `H[k]`'s bits minus `(k << 52) / N`.
const EXP: [u64; 256] = [
    0x0000000000000000,
    0x3ff0000000000000,
    0x3c9b3b4f1a88bf6e,
    0x3feff63da9fb3335,
    0xbc7160139cd8dc5d,
    0x3fefec9a3e778061,
    0xbc905e7a108766d1,
    0x3fefe315e86e7f85,
    0x3c8cd2523567f613,
    0x3fefd9b0d3158574,
    0xbc8bce8023f98efa,
    0x3fefd06b29ddf6de,
    0x3c60f74e61e6c861,
    0x3fefc74518759bc8,
    0x3c90a3e45b33d399,
    0x3fefbe3ecac6f383,
    0x3c979aa65d837b6d,
    0x3fefb5586cf9890f,
    0x3c8eb51a92fdeffc,
    0x3fefac922b7247f7,
    0x3c3ebe3d702f9cd1,
    0x3fefa3ec32d3d1a2,
    0xbc6a033489906e0b,
    0x3fef9b66affed31b,
    0xbc9556522a2fbd0e,
    0x3fef9301d0125b51,
    0xbc5080ef8c4eea55,
    0x3fef8abdc06c31cc,
    0xbc91c923b9d5f416,
    0x3fef829aaea92de0,
    0x3c80d3e3e95c55af,
    0x3fef7a98c8a58e51,
    0xbc801b15eaa59348,
    0x3fef72b83c7d517b,
    0xbc8f1ff055de323d,
    0x3fef6af9388c8dea,
    0x3c8b898c3f1353bf,
    0x3fef635beb6fcb75,
    0xbc96d99c7611eb26,
    0x3fef5be084045cd4,
    0x3c9aecf73e3a2f60,
    0x3fef54873168b9aa,
    0xbc8fe782cb86389d,
    0x3fef4d5022fcd91d,
    0x3c8a6f4144a6c38d,
    0x3fef463b88628cd6,
    0x3c807a05b0e4047d,
    0x3fef3f49917ddc96,
    0x3c968efde3a8a894,
    0x3fef387a6e756238,
    0x3c875e18f274487d,
    0x3fef31ce4fb2a63f,
    0x3c80472b981fe7f2,
    0x3fef2b4565e27cdd,
    0xbc96b87b3f71085e,
    0x3fef24dfe1f56381,
    0x3c82f7e16d09ab31,
    0x3fef1e9df51fdee1,
    0xbc3d219b1a6fbffa,
    0x3fef187fd0dad990,
    0x3c8b3782720c0ab4,
    0x3fef1285a6e4030b,
    0x3c6e149289cecb8f,
    0x3fef0cafa93e2f56,
    0x3c834d754db0abb6,
    0x3fef06fe0a31b715,
    0x3c864201e2ac744c,
    0x3fef0170fc4cd831,
    0x3c8fdd395dd3f84a,
    0x3feefc08b26416ff,
    0xbc86a3803b8e5b04,
    0x3feef6c55f929ff1,
    0xbc924aedcc4b5068,
    0x3feef1a7373aa9cb,
    0xbc9907f81b512d8e,
    0x3feeecae6d05d866,
    0xbc71d1e83e9436d2,
    0x3feee7db34e59ff7,
    0xbc991919b3ce1b15,
    0x3feee32dc313a8e5,
    0x3c859f48a72a4c6d,
    0x3feedea64c123422,
    0xbc9312607a28698a,
    0x3feeda4504ac801c,
    0xbc58a78f4817895b,
    0x3feed60a21f72e2a,
    0xbc7c2c9b67499a1b,
    0x3feed1f5d950a897,
    0x3c4363ed60c2ac11,
    0x3feece086061892d,
    0x3c9666093b0664ef,
    0x3feeca41ed1d0057,
    0x3c6ecce1daa10379,
    0x3feec6a2b5c13cd0,
    0x3c93ff8e3f0f1230,
    0x3feec32af0d7d3de,
    0x3c7690cebb7aafb0,
    0x3feebfdad5362a27,
    0x3c931dbdeb54e077,
    0x3feebcb299fddd0d,
    0xbc8f94340071a38e,
    0x3feeb9b2769d2ca7,
    0xbc87deccdc93a349,
    0x3feeb6daa2cf6642,
    0xbc78dec6bd0f385f,
    0x3feeb42b569d4f82,
    0xbc861246ec7b5cf6,
    0x3feeb1a4ca5d920f,
    0x3c93350518fdd78e,
    0x3feeaf4736b527da,
    0x3c7b98b72f8a9b05,
    0x3feead12d497c7fd,
    0x3c9063e1e21c5409,
    0x3feeab07dd485429,
    0x3c34c7855019c6ea,
    0x3feea9268a5946b7,
    0x3c9432e62b64c035,
    0x3feea76f15ad2148,
    0xbc8ce44a6199769f,
    0x3feea5e1b976dc09,
    0xbc8c33c53bef4da8,
    0x3feea47eb03a5585,
    0xbc845378892be9ae,
    0x3feea34634ccc320,
    0xbc93cedd78565858,
    0x3feea23882552225,
    0x3c5710aa807e1964,
    0x3feea155d44ca973,
    0xbc93b3efbf5e2228,
    0x3feea09e667f3bcd,
    0xbc6a12ad8734b982,
    0x3feea012750bdabf,
    0xbc6367efb86da9ee,
    0x3fee9fb23c651a2f,
    0xbc80dc3d54e08851,
    0x3fee9f7df9519484,
    0xbc781f647e5a3ecf,
    0x3fee9f75e8ec5f74,
    0xbc86ee4ac08b7db0,
    0x3fee9f9a48a58174,
    0xbc8619321e55e68a,
    0x3fee9feb564267c9,
    0x3c909ccb5e09d4d3,
    0x3feea0694fde5d3f,
    0xbc7b32dcb94da51d,
    0x3feea11473eb0187,
    0x3c94ecfd5467c06b,
    0x3feea1ed0130c132,
    0x3c65ebe1abd66c55,
    0x3feea2f336cf4e62,
    0xbc88a1c52fb3cf42,
    0x3feea427543e1a12,
    0xbc9369b6f13b3734,
    0x3feea589994cce13,
    0xbc805e843a19ff1e,
    0x3feea71a4623c7ad,
    0xbc94d450d872576e,
    0x3feea8d99b4492ed,
    0x3c90ad675b0e8a00,
    0x3feeaac7d98a6699,
    0x3c8db72fc1f0eab4,
    0x3feeace5422aa0db,
    0xbc65b6609cc5e7ff,
    0x3feeaf3216b5448c,
    0x3c7bf68359f35f44,
    0x3feeb1ae99157736,
    0xbc93091fa71e3d83,
    0x3feeb45b0b91ffc6,
    0xbc5da9b88b6c1e29,
    0x3feeb737b0cdc5e5,
    0xbc6c23f97c90b959,
    0x3feeba44cbc8520f,
    0xbc92434322f4f9aa,
    0x3feebd829fde4e50,
    0xbc85ca6cd7668e4b,
    0x3feec0f170ca07ba,
    0x3c71affc2b91ce27,
    0x3feec49182a3f090,
    0x3c6dd235e10a73bb,
    0x3feec86319e32323,
    0xbc87c50422622263,
    0x3feecc667b5de565,
    0x3c8b1c86e3e231d5,
    0x3feed09bec4a2d33,
    0xbc91bbd1d3bcbb15,
    0x3feed503b23e255d,
    0x3c90cc319cee31d2,
    0x3feed99e1330b358,
    0x3c8469846e735ab3,
    0x3feede6b5579fdbf,
    0xbc82dfcd978e9db4,
    0x3feee36bbfd3f37a,
    0x3c8c1a7792cb3387,
    0x3feee89f995ad3ad,
    0xbc907b8f4ad1d9fa,
    0x3feeee07298db666,
    0xbc55c3d956dcaeba,
    0x3feef3a2b84f15fb,
    0xbc90a40e3da6f640,
    0x3feef9728de5593a,
    0xbc68d6f438ad9334,
    0x3feeff76f2fb5e47,
    0xbc91eee26b588a35,
    0x3fef05b030a1064a,
    0x3c74ffd70a5fddcd,
    0x3fef0c1e904bc1d2,
    0xbc91bdfbfa9298ac,
    0x3fef12c25bd71e09,
    0x3c736eae30af0cb3,
    0x3fef199bdd85529c,
    0x3c8ee3325c9ffd94,
    0x3fef20ab5fffd07a,
    0x3c84e08fd10959ac,
    0x3fef27f12e57d14b,
    0x3c63cdaf384e1a67,
    0x3fef2f6d9406e7b5,
    0x3c676b2c6c921968,
    0x3fef3720dcef9069,
    0xbc808a1883ccb5d2,
    0x3fef3f0b555dc3fa,
    0xbc8fad5d3ffffa6f,
    0x3fef472d4a07897c,
    0xbc900dae3875a949,
    0x3fef4f87080d89f2,
    0x3c74a385a63d07a7,
    0x3fef5818dcfba487,
    0xbc82919e2040220f,
    0x3fef60e316c98398,
    0x3c8e5a50d5c192ac,
    0x3fef69e603db3285,
    0x3c843a59ac016b4b,
    0x3fef7321f301b460,
    0xbc82d52107b43e1f,
    0x3fef7c97337b9b5f,
    0xbc892ab93b470dc9,
    0x3fef864614f5a129,
    0x3c74b604603a88d3,
    0x3fef902ee78b3ff6,
    0x3c83c5ec519d7271,
    0x3fef9a51fbc74c83,
    0xbc8ff7128fd391f0,
    0x3fefa4afa2a490da,
    0xbc8dae98e223747d,
    0x3fefaf482d8e67f1,
    0x3c8ec3bc41aa2008,
    0x3fefba1bee615a27,
    0x3c842b94c3a9eb32,
    0x3fefc52b376bba97,
    0x3c8a64a931d185ee,
    0x3fefd0765b6e4540,
    0xbc8e37bae43be3ed,
    0x3fefdbfdad9cbe14,
    0x3c77893b4d91cd9d,
    0x3fefe7c1819e90d8,
    0x3c5305c14160cc89,
    0x3feff3c22b8f71f1,
];

fn top12(x: f64) -> u32 {
    (x.to_bits() >> 52) as u32
}

fn log_inline(ix: u64) -> (f64, f64) {
    let tmp = ix.wrapping_sub(OFF);
    let i = ((tmp >> (52 - TABLE_BITS)) % 128) as usize;
    let k = (tmp as i64) >> 52;
    let iz = ix.wrapping_sub(tmp & (0xfff << 52));
    let z = f64::from_bits(iz);
    let kd = k as f64;
    let (invc, logc, logctail) = T[i];
    let r = z.mul_add(invc, -1.0);
    let t1 = kd * LN2HI + logc;
    let t2 = t1 + r;
    let lo1 = kd * LN2LO + logctail;
    let lo2 = t1 - t2 + r;
    let ar = A[0] * r;
    let ar2 = r * ar;
    let ar3 = r * ar2;
    let hi = t2 + ar2;
    let lo3 = ar.mul_add(r, -ar2);
    let lo4 = t2 - hi + ar2;
    let p = ar3 * (A[1] + r * A[2] + ar2 * (A[3] + r * A[4] + ar2 * (A[5] + r * A[6])));
    let lo = lo1 + lo2 + lo3 + lo4 + p;
    let y = hi + lo;
    (y, hi - y + lo)
}

fn specialcase(tmp: f64, mut sbits: u64, ki: u64) -> f64 {
    if ki & 0x80000000 == 0 {
        sbits = sbits.wrapping_sub(1009u64 << 52);
        let scale = f64::from_bits(sbits);
        return f64::from_bits(0x7f00000000000000) * (scale + scale * tmp);
    }
    sbits = sbits.wrapping_add(1022u64 << 52);
    let scale = f64::from_bits(sbits);
    let mut y = scale + scale * tmp;
    if y.abs() < 1.0 {
        let one = if y < 0.0 { -1.0 } else { 1.0 };
        let mut lo = scale - y + scale * tmp;
        let hi = one + y;
        lo = one - hi + y + lo;
        y = (hi + lo) - one;
        if y == 0.0 {
            y = f64::from_bits(sbits & 0x8000000000000000);
        }
    }
    f64::from_bits(0x0010000000000000) * y
}

fn exp_inline(x: f64, xtail: f64, sign_bias: u64) -> f64 {
    let mut abstop = top12(x) & 0x7ff;
    if abstop.wrapping_sub(top12(f64::from_bits(0x3c90000000000000)))
        >= top12(512.0).wrapping_sub(top12(f64::from_bits(0x3c90000000000000)))
    {
        if abstop.wrapping_sub(top12(f64::from_bits(0x3c90000000000000))) >= 0x80000000 {
            let one = 1.0 + x;
            return if sign_bias != 0 { -one } else { one };
        }
        if abstop >= top12(1024.0) {
            let negative = sign_bias != 0;
            return if x.to_bits() >> 63 != 0 {
                if negative { -0.0 } else { 0.0 }
            } else if negative {
                f64::NEG_INFINITY
            } else {
                f64::INFINITY
            };
        }
        abstop = 0;
    }
    let z = INV_LN2_N * x;
    let mut kd = z + SHIFT;
    let ki = kd.to_bits();
    kd -= SHIFT;
    let mut r = x + kd * NEG_LN2_HI_N + kd * NEG_LN2_LO_N;
    r += xtail;
    let idx = (2 * (ki % 128)) as usize;
    let top = ki.wrapping_add(sign_bias) << (52 - 7);
    let tail = f64::from_bits(EXP[idx]);
    let sbits = EXP[idx + 1].wrapping_add(top);
    let r2 = r * r;
    let tmp = tail + r + r2 * (C2 + r * C3) + r2 * r2 * (C4 + r * C5);
    if abstop == 0 {
        return specialcase(tmp, sbits, ki);
    }
    let scale = f64::from_bits(sbits);
    scale + scale * tmp
}

/// 0 when not an integer, 1 when odd, 2 when even (`iy` a non-zero finite).
fn checkint(iy: u64) -> u32 {
    let e = (iy >> 52 & 0x7ff) as i32;
    if e < 0x3ff {
        return 0;
    }
    if e > 0x3ff + 52 {
        return 2;
    }
    let shift = (0x3ff + 52 - e) as u64;
    if iy & ((1u64 << shift) - 1) != 0 {
        return 0;
    }
    if iy & (1u64 << shift) != 0 {
        return 1;
    }
    2
}

fn zeroinfnan(i: u64) -> bool {
    (2u64.wrapping_mul(i)).wrapping_sub(1) >= (2 * f64::INFINITY.to_bits()) - 1
}

/// musl's `pow(x, y)`.
pub(crate) fn pow(x: f64, y: f64) -> f64 {
    let mut sign_bias = 0u64;
    let mut ix = x.to_bits();
    let iy = y.to_bits();
    let mut topx = top12(x);
    let topy = top12(y);
    if topx.wrapping_sub(0x001) >= 0x7ff - 0x001 || (topy & 0x7ff).wrapping_sub(0x3be) >= 0x43e - 0x3be {
        if zeroinfnan(iy) {
            if 2u64.wrapping_mul(iy) == 0 {
                return 1.0;
            }
            if ix == 1.0f64.to_bits() {
                return 1.0;
            }
            if 2u64.wrapping_mul(ix) > 2 * f64::INFINITY.to_bits()
                || 2u64.wrapping_mul(iy) > 2 * f64::INFINITY.to_bits()
            {
                return x + y;
            }
            if 2u64.wrapping_mul(ix) == 2 * 1.0f64.to_bits() {
                return 1.0;
            }
            if (2u64.wrapping_mul(ix) < 2 * 1.0f64.to_bits()) == (iy >> 63 == 0) {
                return 0.0;
            }
            return y * y;
        }
        if zeroinfnan(ix) {
            let mut x2 = x * x;
            if ix >> 63 != 0 && checkint(iy) == 1 {
                x2 = -x2;
            }
            return if iy >> 63 != 0 { 1.0 / x2 } else { x2 };
        }
        if ix >> 63 != 0 {
            let yint = checkint(iy);
            if yint == 0 {
                return f64::NAN;
            }
            if yint == 1 {
                sign_bias = SIGN_BIAS;
            }
            ix &= 0x7fffffffffffffff;
            topx &= 0x7ff;
        }
        if (topy & 0x7ff).wrapping_sub(0x3be) >= 0x43e - 0x3be {
            if ix == 1.0f64.to_bits() {
                return 1.0;
            }
            if (topy & 0x7ff) < 0x3be {
                return if ix > 1.0f64.to_bits() { 1.0 + y } else { 1.0 - y };
            }
            return if (ix > 1.0f64.to_bits()) == (topy < 0x800) { f64::INFINITY } else { 0.0 };
        }
        if topx == 0 {
            ix = (x * f64::from_bits(0x4330000000000000)).to_bits();
            ix &= 0x7fffffffffffffff;
            ix = ix.wrapping_sub(52u64 << 52);
        }
    }
    let (hi, lo) = log_inline(ix);
    let ehi = y * hi;
    let elo = y * lo + y.mul_add(hi, -ehi);
    exp_inline(ehi, elo, sign_bias)
}
