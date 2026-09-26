// Compare les appels du frontend aux routes réellement chargées par Laravel.
// Usage : node scripts/check-api-routes.mjs [chemin/vers/php]
import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const routes = JSON.parse(execFileSync(process.argv[2] || 'php', [
  'artisan', 'route:list', '--path=api', '--json', '--except-vendor',
], { cwd: join(root, '../backend'), encoding: 'utf8' }))
const failures = []
let calls = 0
let checks = 0

function* files(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) yield* files(path)
    else if (/\.tsx?$/.test(path)) yield path
  }
}

function paths(node) {
  if (ts.isStringLiteralLike(node)) return [node.text]
  if (ts.isConditionalExpression(node)) return [...paths(node.whenTrue), ...paths(node.whenFalse)]
  if (ts.isTemplateExpression(node)) {
    let result = [node.head.text]
    for (const span of node.templateSpans) {
      // Les deux valeurs autorisées par OrgSection ; les autres expressions sont des IDs ou des query params.
      const values = span.expression.getText() === 'endpoint' ? ['tribes', 'departments'] : ['1']
      result = result.flatMap(prefix => values.map(value => prefix + value + span.literal.text))
    }
    return result
  }
  // Adresse construite par une fonction locale (ex. url(page), membersUrl(1, true)) : on suit sa definition.
  if (ts.isCallExpression(node) && ts.isIdentifier(node.expression)) {
    const body = localBuilder(node.getSourceFile(), node.expression.text)
    if (body) return paths(body)
  }
  throw new Error(`Expression URL à prendre en charge : ${node.getText()}`)
}

/** Corps (expression) d'une fonction flechee declaree dans le fichier, eventuellement dans useCallback(). */
function localBuilder(source, name) {
  let found
  const visit = (n) => {
    if (found) return
    if (ts.isVariableDeclaration(n) && n.name.getText() === name && n.initializer) {
      let init = n.initializer
      if (ts.isCallExpression(init) && init.arguments[0]) init = init.arguments[0]
      if (ts.isArrowFunction(init)) {
        if (!ts.isBlock(init.body)) found = init.body
        else init.body.statements.forEach((st) => { if (!found && ts.isReturnStatement(st) && st.expression) found = st.expression })
      }
    }
    ts.forEachChild(n, visit)
  }
  visit(source)
  return found
}

for (const file of files(join(root, 'src'))) {
  const source = ts.createSourceFile(file, readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true)
  function visit(node) {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 'api') {
      calls++
      const location = `${relative(root, file)}:${source.getLineAndCharacterOfPosition(node.getStart()).line + 1}`
      try {
        const options = node.arguments[1]
        const methodProperty = options && ts.isObjectLiteralExpression(options)
          ? options.properties.find(p => p.name?.getText() === 'method') : undefined
        const method = methodProperty ? methodProperty.initializer.text : 'GET'
        if (!method) throw new Error('Méthode dynamique non prise en charge')
        for (const path of paths(node.arguments[0])) {
          const uri = `api${path.split('?')[0]}`
          // Events utilise déjà POST multipart + _method=PUT, requis pour les fichiers PHP.
          const spoofed = method === 'POST' && /^api\/admin\/events\/\d+$/.test(uri)
            && source.text.includes("fd.append('_method', 'PUT')")
          const effectiveMethod = spoofed ? 'PUT' : method
          const match = routes.find(route => route.method.split('|').includes(effectiveMethod)
            && route.uri.split('/').length === uri.split('/').length
            && route.uri.split('/').every((part, i) => /^\{[^}]+\}$/.test(part) || part === uri.split('/')[i]))
          checks++
          if (!match) failures.push(`${location}: route absente : ${effectiveMethod} ${uri}`)
          if (/[^\x00-\x7F]/.test(decodeURIComponent(uri))) failures.push(`${location}: URL technique accentuée : ${uri}`)
        }
      } catch (error) { failures.push(`${location}: ${error.message}`) }
    }
    // Détecte également les classes techniques accidentellement traduites.
    if (ts.isJsxAttribute(node) && node.name.getText() === 'className' && node.initializer) {
      const value = node.initializer.getText()
      if (/[^\x00-\x7F]/.test(value)) failures.push(`${relative(root, file)}: classe CSS à vérifier : ${value}`)
    }
    ts.forEachChild(node, visit)
  }
  visit(source)
}
if (!calls) failures.push('Aucun appel API trouvé')
if (failures.length) {
  console.error(failures.join('\n'))
  process.exitCode = 1
} else {
  console.log(`OK : ${calls} appels API, ${checks} combinaisons URL/méthode, ${routes.length} routes Laravel. Classes CSS sans accents.`)
}
