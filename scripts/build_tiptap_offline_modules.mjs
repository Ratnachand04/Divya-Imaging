import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');
const outputRoot = path.join(repoRoot, 'assets', 'vendor', 'tiptap');
const modulesRoot = path.join(outputRoot, 'modules');

const rootPackages = {
  '@tiptap/core': '2.27.2',
  '@tiptap/starter-kit': '2.27.2',
  '@tiptap/extension-link': '2.27.2',
  '@tiptap/extension-underline': '2.27.2',
  '@tiptap/extension-text-align': '2.27.2',
  '@tiptap/extension-placeholder': '2.27.2',
  '@tiptap/extension-image': '2.27.2',
  '@tiptap/extension-task-list': '2.27.2',
  '@tiptap/extension-task-item': '2.27.2',
  '@tiptap/extension-highlight': '2.27.2',
  '@tiptap/extension-text-style': '2.27.2',
  '@tiptap/extension-color': '2.27.2',
  '@tiptap/extension-superscript': '2.27.2',
  '@tiptap/extension-subscript': '2.27.2',
};

const importMap = { imports: {} };
const packageCache = new Map();
const processedSpecifiers = new Set();
const processingSpecifiers = new Set();

const isBareSpecifier = (specifier) => {
  return (
    !specifier.startsWith('.')
    && !specifier.startsWith('/')
    && !specifier.startsWith('http://')
    && !specifier.startsWith('https://')
    && !specifier.startsWith('data:')
    && !specifier.startsWith('node:')
  );
};

const parseSpecifier = (specifier) => {
  if (specifier.startsWith('@')) {
    const parts = specifier.split('/');
    const packageName = `${parts[0]}/${parts[1]}`;
    const subpath = parts.slice(2).join('/');
    return { packageName, subpath };
  }

  const parts = specifier.split('/');
  const packageName = parts[0];
  const subpath = parts.slice(1).join('/');
  return { packageName, subpath };
};

const collectImports = (code) => {
  const imports = new Set();
  const regex = /(?:import|export)\s+(?:[^'"`]*from\s*)?['"`]([^'"`]+)['"`]/g;
  let match;

  while ((match = regex.exec(code)) !== null) {
    imports.add(match[1]);
  }

  return Array.from(imports);
};

const toWebPath = (absolutePath) => {
  const relative = path.relative(repoRoot, absolutePath).replace(/\\/g, '/');
  return `/${relative}`;
};

const toLocalModuleFile = (packageName, subpath) => {
  const segments = [modulesRoot, packageName];
  if (subpath) {
    segments.push(...subpath.split('/'));
  }
  return path.join(...segments, 'index.js');
};

const fetchText = async (url) => {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(`Failed to fetch ${url}: ${response.status} ${response.statusText}`);
  }
  return response.text();
};

const fetchJson = async (url) => {
  const text = await fetchText(url);
  return JSON.parse(text);
};

const pickModuleEntry = (pkgJson) => {
  if (typeof pkgJson.module === 'string' && pkgJson.module.trim() !== '') {
    return pkgJson.module;
  }

  const exportsField = pkgJson.exports;
  if (typeof exportsField === 'string' && exportsField.trim() !== '') {
    return exportsField;
  }

  if (exportsField && typeof exportsField === 'object') {
    const dotEntry = exportsField['.'];
    if (typeof dotEntry === 'string' && dotEntry.trim() !== '') {
      return dotEntry;
    }
    if (dotEntry && typeof dotEntry === 'object') {
      if (typeof dotEntry.import === 'string' && dotEntry.import.trim() !== '') {
        return dotEntry.import;
      }
      if (typeof dotEntry.default === 'string' && dotEntry.default.trim() !== '') {
        return dotEntry.default;
      }
    }
  }

  if (typeof pkgJson.main === 'string' && pkgJson.main.trim() !== '') {
    return pkgJson.main;
  }

  return 'index.js';
};

const resolvePackageInfo = async (packageName, requestedVersion) => {
  if (packageCache.has(packageName)) {
    return packageCache.get(packageName);
  }

  const versionHint = requestedVersion || 'latest';
  const packageJsonUrl = `https://unpkg.com/${packageName}@${versionHint}/package.json`;
  const packageJson = await fetchJson(packageJsonUrl);

  const info = {
    packageName,
    version: packageJson.version,
    packageJson,
  };

  packageCache.set(packageName, info);
  return info;
};

const fetchModuleCode = async (packageName, version, subpath, packageJson) => {
  const candidates = [];

  if (subpath) {
    if (packageName === '@tiptap/pm') {
      candidates.push(`${subpath}/dist/index.js`);
    }

    candidates.push(subpath);
    candidates.push(`${subpath}.js`);
    candidates.push(`${subpath}/index.js`);
  } else {
    candidates.push(pickModuleEntry(packageJson));
  }

  const uniqueCandidates = Array.from(new Set(candidates));
  let lastError = null;

  for (const candidate of uniqueCandidates) {
    const normalizedCandidate = candidate.replace(/^\.\//, '');
    const moduleUrl = `https://unpkg.com/${packageName}@${version}/${normalizedCandidate}`;

    try {
      const code = await fetchText(moduleUrl);
      return { code, sourcePath: normalizedCandidate };
    } catch (error) {
      lastError = error;
    }
  }

  throw lastError || new Error(`Unable to resolve module file for ${packageName}/${subpath || ''}`);
};

const processSpecifier = async (specifier, versionHints = {}) => {
  if (!isBareSpecifier(specifier)) {
    return;
  }

  if (processedSpecifiers.has(specifier) || processingSpecifiers.has(specifier)) {
    return;
  }

  processingSpecifiers.add(specifier);

  const { packageName, subpath } = parseSpecifier(specifier);
  const requestedVersion = rootPackages[packageName] || versionHints[packageName] || 'latest';

  const packageInfo = await resolvePackageInfo(packageName, requestedVersion);
  const { code, sourcePath } = await fetchModuleCode(
    packageInfo.packageName,
    packageInfo.version,
    subpath,
    packageInfo.packageJson,
  );

  const localFile = toLocalModuleFile(packageInfo.packageName, subpath);
  await mkdir(path.dirname(localFile), { recursive: true });
  await writeFile(localFile, code, 'utf8');

  importMap.imports[specifier] = toWebPath(localFile);

  const dependencyVersions = {
    ...(packageInfo.packageJson.dependencies || {}),
    ...(packageInfo.packageJson.peerDependencies || {}),
    ...(packageInfo.packageJson.optionalDependencies || {}),
  };

  const childImports = collectImports(code)
    .filter((childSpecifier) => isBareSpecifier(childSpecifier));

  for (const childSpecifier of childImports) {
    await processSpecifier(childSpecifier, dependencyVersions);
  }

  processedSpecifiers.add(specifier);
  processingSpecifiers.delete(specifier);

  console.log(`Fetched ${specifier} from ${packageInfo.packageName}@${packageInfo.version}/${sourcePath}`);
};

const main = async () => {
  await mkdir(outputRoot, { recursive: true });
  await mkdir(modulesRoot, { recursive: true });

  for (const specifier of Object.keys(rootPackages)) {
    await processSpecifier(specifier, rootPackages);
  }

  const sortedImports = Object.keys(importMap.imports)
    .sort()
    .reduce((accumulator, key) => {
      accumulator[key] = importMap.imports[key];
      return accumulator;
    }, {});

  const importMapPath = path.join(outputRoot, 'importmap.json');
  const importMapJson = JSON.stringify({ imports: sortedImports }, null, 2);
  await writeFile(importMapPath, importMapJson + '\n', 'utf8');

  console.log(`Wrote import map to ${importMapPath}`);
};

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
