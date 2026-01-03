/**
 * @file
 * Sync script to bundle Safe Protocol Kit for browser use.
 *
 * This script runs after npm install and creates a browser-compatible bundle
 * of the Safe Protocol Kit in the libraries directory.
 *
 * Usage: npm install (runs automatically via postinstall)
 */

const esbuild = require('esbuild');
const { polyfillNode } = require('esbuild-plugin-polyfill-node');
const fs = require('fs');
const path = require('path');

const MODULE_ROOT = path.resolve(__dirname, '..');
const LIBRARIES_DIR = path.join(MODULE_ROOT, 'libraries', 'safe-sdk');
const BUNDLE_ENTRY = path.join(MODULE_ROOT, 'scripts', 'sdk-entry.js');

// Create entry point for bundling
const entryContent = `
// Safe Protocol Kit browser entry point
import Safe from '@safe-global/protocol-kit';
import { EthSafeSignature } from '@safe-global/protocol-kit';

// Expose to global scope for Drupal
window.Safe = Safe;
window.EthSafeSignature = EthSafeSignature;

// Also expose as ES module style
export { Safe, EthSafeSignature };
`;

async function main() {
  console.log('[Safe SDK Sync] Starting library synchronization...');

  // Ensure libraries directory exists
  if (!fs.existsSync(LIBRARIES_DIR)) {
    fs.mkdirSync(LIBRARIES_DIR, { recursive: true });
    console.log(`[Safe SDK Sync] Created directory: ${LIBRARIES_DIR}`);
  }

  // Create entry point file
  fs.writeFileSync(BUNDLE_ENTRY, entryContent.trim());
  console.log('[Safe SDK Sync] Created bundle entry point');

  // Bundle using esbuild
  const outputFile = path.join(LIBRARIES_DIR, 'protocol-kit.bundle.js');

  try {
    console.log('[Safe SDK Sync] Bundling Safe Protocol Kit...');

    // Use esbuild API with polyfill plugin
    await esbuild.build({
      entryPoints: [BUNDLE_ENTRY],
      bundle: true,
      format: 'iife',
      platform: 'browser',
      target: 'es2020',
      outfile: outputFile,
      minify: true,
      sourcemap: true,
      plugins: [
        polyfillNode({
          // Polyfill Node.js built-ins for browser
          polyfills: {
            buffer: true,
            crypto: true,
            stream: true,
            util: true,
            events: true,
            process: true,
          },
        }),
      ],
      define: {
        'process.env.NODE_ENV': '"production"',
        'global': 'globalThis',
      },
    });

    console.log(`[Safe SDK Sync] Bundle created: ${outputFile}`);

    // Get bundle size
    const stats = fs.statSync(outputFile);
    const sizeMB = (stats.size / (1024 * 1024)).toFixed(2);
    console.log(`[Safe SDK Sync] Bundle size: ${sizeMB} MB`);

    // Clean up entry point
    fs.unlinkSync(BUNDLE_ENTRY);

    console.log('[Safe SDK Sync] Library synchronization complete!');
  } catch (error) {
    console.error('[Safe SDK Sync] Error bundling SDK:', error.message);
    // Clean up entry point on error
    if (fs.existsSync(BUNDLE_ENTRY)) {
      fs.unlinkSync(BUNDLE_ENTRY);
    }
    process.exit(1);
  }
}

main();
