import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const rootDir = process.cwd();

function readText( relativePath ) {
	return fs.readFileSync( path.join( rootDir, relativePath ), 'utf8' );
}

function readJson( relativePath ) {
	return JSON.parse( readText( relativePath ) );
}

function matchRequired( label, text, pattern ) {
	const match = text.match( pattern );

	if ( ! match ) {
		throw new Error( `Could not read ${ label }.` );
	}

	return match[1].trim();
}

const pluginFile = 'tporret-api-data-importer.php';
const pluginSource = readText( pluginFile );
const readmeSource = readText( 'readme.txt' );
const repoReadmeSource = readText( 'README.md' );
const packageJson = readJson( 'package.json' );
const packageLockJson = readJson( 'package-lock.json' );

const pluginHeaderVersion = matchRequired(
	`${ pluginFile } Version header`,
	pluginSource,
	/^ \* Version:\s*([^\r\n]+)/m
);

const pluginConstantVersion = matchRequired(
	'TPORAPDI_PLUGIN_VERSION constant',
	pluginSource,
	/define\(\s*'TPORAPDI_PLUGIN_VERSION'\s*,\s*'([^']+)'\s*\)/
);

const stableTag = matchRequired(
	'readme.txt Stable tag',
	readmeSource,
	/^Stable tag:\s*([^\r\n]+)/m
);

const latestRelease = matchRequired(
	'README.md Latest Release heading',
	repoReadmeSource,
	/^## Latest Release \(([^)]+)\)/m
);

const pluginTestedUpTo = matchRequired(
	`${ pluginFile } Tested up to header`,
	pluginSource,
	/^ \* Tested up to:\s*([^\r\n]+)/m
);

const readmeTestedUpTo = matchRequired(
	'readme.txt Tested up to header',
	readmeSource,
	/^Tested up to:\s*([^\r\n]+)/m
);

const values = new Map( [
	[ `${ pluginFile } Version`, pluginHeaderVersion ],
	[ 'TPORAPDI_PLUGIN_VERSION', pluginConstantVersion ],
	[ 'readme.txt Stable tag', stableTag ],
	[ 'README.md Latest Release', latestRelease ],
	[ 'package.json version', packageJson.version ],
	[ 'package-lock.json version', packageLockJson.version ],
	[ 'package-lock root package version', packageLockJson.packages?.['']?.version ],
] );

const expected = pluginHeaderVersion;
const mismatches = [];

for ( const [ label, value ] of values.entries() ) {
	if ( value !== expected ) {
		mismatches.push( `${ label } is ${ value || '(missing)' }, expected ${ expected }` );
	}
}

if ( mismatches.length > 0 ) {
	console.error( 'Release metadata is not aligned:' );
	for ( const mismatch of mismatches ) {
		console.error( `- ${ mismatch }` );
	}
	process.exit( 1 );
}

if ( pluginTestedUpTo !== readmeTestedUpTo ) {
	console.error( 'WordPress compatibility metadata is not aligned:' );
	console.error( `- ${ pluginFile } Tested up to is ${ pluginTestedUpTo }` );
	console.error( `- readme.txt Tested up to is ${ readmeTestedUpTo }` );
	process.exit( 1 );
}

console.log( `Release metadata aligned at ${ expected }.` );
console.log( `WordPress compatibility tested up to ${ pluginTestedUpTo }.` );