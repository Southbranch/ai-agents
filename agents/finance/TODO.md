# Finance POC – Status & TODO

_Senast uppdaterad: 2026-09-10_

## Sammanfattning

Målet var en enkel Finance POC:

- GitHub Actions som scheduler
- PHP-agent i `run.php`
- MCP-endpoint i `finance-mcp.php`
- Ingen databas eller state i första versionen
- Manuell och automatisk körning
- Körning på svensk tid

## Problem vi hade

1. **Gammal workflow kördes**
   GitHub-runs visade gamla commits, exempelvis `9ba4754`. Det berodde på att gamla körningar kördes om, inte att GitHub använde den nya YAML-filen.

2. **Förvirring kring UTC och svensk tid**
   Cron använder UTC som standard. Vi testade därför både:
   - `timezone: Europe/Stockholm`
   - rena UTC-tider, till exempel `15 15 * * *`

3. **Schemalagd körning startade inte**
   Manuell körning och push-trigger fungerade, men GitHub skapade inga riktiga `schedule`-runs. Vi testade flera framtida tider utan resultat.

4. **MCP-klienten fungerade lokalt men timeoutade i GitHub**
   Den ursprungliga PHP-stream-klienten fastnade på GitHub-runnern. Retry löste inte problemet.

5. **Transportproblemet löstes**
   Vi testade direkt med `curl` från GitHub-runnern. Det fungerade. Därefter ändrades PHP-klienten till cURL när tillgängligt, med stream-fallback lokalt.

## Verifierat fungerande

GitHub-run **#9** lyckades automatiskt:

- Commit: `e1f3ad1`
- Event: `push`
- Körningstid: 16 sekunder
- MCP-anrop fungerade
- Finance-resultat kunde hämtas

Det bevisade att GitHub Actions, PHP, MCP och Avanza-anropet fungerar tillsammans.

## Aktuellt läge

Workflowen på `main` innehåller:

```yaml
on:
  workflow_dispatch:
  schedule:
    - cron: '25 15 * * *'
```

Det motsvarar 17:25 svensk tid den dagen testet gjordes.

Senaste kontrollen visade:

- Manuell körning: fungerar
- Push-trigger: fungerar
- MCP-anrop från GitHub: fungerar
- Schedule-trigger: ✅ **löst** – Run #12 ("Scheduled") kördes automatiskt 2026-09-10 kl 17:31 och lyckades (14s)

## Slutsats

Schedule-triggern fungerar nu. GitHub tog längre tid än väntat på att börja respektera det uppdaterade cron-schemat (`25 15 * * *`), men run #12 bevisar att en riktig `schedule`-event skapades och kördes utan problem. Ingen extern scheduler behövs längre som primär lösning – kan sparas som backup om schedule slutar fungera igen.

Den fungerande vägen är därför:

- använd GitHub Actions-jobbet som det är
- trigga det manuellt eller via push
- alternativt använda en extern scheduler som anropar GitHub `workflow_dispatch` API regelbundet.

## TODO / Nästa steg

- [x] Bevaka om `schedule`-trigger någonsin skapar en run automatiskt — löst: run #12 kördes schemalagt 2026-09-10 17:31
- [ ] Bevaka ytterligare ett par dagar att schedule-triggern fortsätter köra dagligen som förväntat
- [ ] Städa bort `mcp-proxy-old.php` om den inte längre behövs
- [ ] Dokumentera MCP-endpoint (`finance-mcp.php`) och dess kontrakt (request/response)
- [ ] Överväg loggning/notifiering vid misslyckad körning (t.ex. GitHub Issue eller e-post vid fel)
- [ ] Om state/databas blir aktuellt i nästa version: definiera vad som ska sparas (historik över Finance-resultat?)
