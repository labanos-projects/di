// ─── Player Profile View ──────────────────────────────────────────────────────

const FORMAT_ABBR = { Fourball: '4B', Greensome: 'GS', Foursome: 'FS', Singles: 'S' };

function ResultBadge({ points }) {
  if (points === 2) return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-green-100 text-green-700">W</span>;
  if (points === 1) return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-700">½</span>;
  return <span className="inline-block px-1.5 py-0.5 rounded text-xs font-bold bg-slate-100 text-slate-500">L</span>;
}

function UpsTag({ ups }) {
  if (!ups) return null;
  return <span className={`text-xs ml-1 font-mono ${ups > 0 ? 'text-green-600' : 'text-red-400'}`}>{ups > 0 ? `+${ups}` : ups}</span>;
}

function MatchDetailsTable({ details }) {
  if (!details || details.length === 0) {
    return <p className="text-xs text-slate-400 py-2 text-center">No match details available.</p>;
  }
  return (
    <table className="w-full text-xs">
      <thead>
        <tr className="text-slate-400 border-b border-slate-200">
          <th className="py-1.5 text-left pr-3 font-medium">Rnd</th>
          <th className="py-1.5 text-left pr-3 font-medium">Format</th>
          <th className="py-1.5 text-left pr-3 font-medium">Partner</th>
          <th className="py-1.5 text-left font-medium">Opponents</th>
          <th className="py-1.5 text-right font-medium">Result</th>
        </tr>
      </thead>
      <tbody>
        {details.map(md => (
          <tr key={md.match_id} className="border-t border-slate-100">
            <td className="py-1.5 pr-3 text-slate-400 font-mono">{md.round_number}</td>
            <td className="py-1.5 pr-3 text-slate-500">{FORMAT_ABBR[md.format] || md.format}</td>
            <td className="py-1.5 pr-3 text-slate-600">
              {md.partners && md.partners.length > 0
                ? md.partners.join(' & ')
                : <span className="text-slate-300">—</span>}
            </td>
            <td className="py-1.5 text-slate-600">
              {md.opponents && md.opponents.length > 0
                ? md.opponents.join(' & ')
                : <span className="text-slate-300">—</span>}
            </td>
            <td className="py-1.5 text-right whitespace-nowrap">
              <ResultBadge points={md.points} />
              <UpsTag ups={md.ups} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function PlayerView({ playerId, onBack }) {
  const [data, setData]         = React.useState(null);
  const [error, setError]       = React.useState(null);
  const [expandedYear, setExpY] = React.useState(null);

  React.useEffect(() => {
    if (!playerId) return;
    setData(null);
    setExpY(null);
    fetch(`${API}/player_stats.php?id=${playerId}`)
      .then(r => r.json())
      .then(setData)
      .catch(() => setError('Could not load player.'));
  }, [playerId]);

  if (error) return <ErrorMsg msg={error} />;
  if (!data)  return <Spinner />;

  const t = TEAM[data.team];

  return (
    <div className="max-w-xl mx-auto p-4">
      <button onClick={onBack} className="mb-4 text-sm text-slate-500 hover:text-slate-800">← Back</button>

      {/* Team header card */}
      <div className={`rounded-2xl p-6 mb-4 text-white ${t.bg}`}>
        <div className="text-sm font-medium opacity-80 mb-1">{t.label} Team</div>
        <div className="text-2xl font-black">{data.name}</div>
        <div className="mt-4 grid grid-cols-4 gap-4 text-center">
          <div><div className="text-2xl font-black mono">{fmtPts(data.total_points)}</div><div className="text-xs opacity-70">Points</div></div>
          <div><div className="text-2xl font-black mono">{data.matches_played}</div><div className="text-xs opacity-70">Matches</div></div>
          <div><div className="text-2xl font-black mono">{data.total_ups}</div><div className="text-xs opacity-70">UPs</div></div>
          <div><div className="text-2xl font-black mono">{fmtAvg(data.avg_points)}</div><div className="text-xs opacity-70">Avg</div></div>
        </div>
      </div>

      {/* W/H/L summary */}
      <div className="bg-white rounded-2xl shadow-sm p-4 mb-4 flex justify-around text-center">
        <div><div className="text-xl font-bold text-green-600 mono">{data.wins}</div><div className="text-xs text-slate-400">Wins</div></div>
        <div><div className="text-xl font-bold text-amber-500 mono">{data.halves}</div><div className="text-xs text-slate-400">Halved</div></div>
        <div><div className="text-xl font-bold text-slate-400 mono">{data.losses}</div><div className="text-xs text-slate-400">Losses</div></div>
      </div>

      {/* Year by year — expandable */}
      <div className="bg-white rounded-2xl shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-100 text-xs font-bold uppercase tracking-wide text-slate-400">Year by Year</div>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-right text-xs text-slate-400 border-b border-slate-50">
              <th className="px-4 py-2 text-left">Year</th>
              <th className="px-4 py-2">M</th>
              <th className="px-4 py-2">Pts</th>
              <th className="px-4 py-2">W</th>
              <th className="px-4 py-2">H</th>
              <th className="px-4 py-2">L</th>
              <th className="px-4 py-2">UPs</th>
            </tr>
          </thead>
          <tbody>
            {(data.years || []).map(y => {
              const isOpen = expandedYear === y.year;
              return (
                <React.Fragment key={y.year}>
                  {/* Summary row — click to expand */}
                  <tr
                    className={`border-b border-slate-50 text-right cursor-pointer select-none transition-colors ${isOpen ? 'bg-blue-50' : 'hover:bg-slate-50'}`}
                    onClick={() => setExpY(isOpen ? null : y.year)}
                  >
                    <td className="px-4 py-2 text-left font-medium text-slate-700">
                      <span className="inline-flex items-center gap-1.5">
                        <span className="text-slate-400 text-xs w-3">{isOpen ? '▾' : '▸'}</span>
                        {y.year}
                      </span>
                    </td>
                    <td className="px-4 py-2 mono text-slate-500">{y.matches}</td>
                    <td className="px-4 py-2 mono font-bold">{fmtPts(y.points)}</td>
                    <td className="px-4 py-2 mono text-green-600">{y.wins}</td>
                    <td className="px-4 py-2 mono text-amber-500">{y.halves}</td>
                    <td className="px-4 py-2 mono text-slate-400">{y.losses}</td>
                    <td className="px-4 py-2 mono text-slate-500">{y.ups}</td>
                  </tr>
                  {/* Expanded match details */}
                  {isOpen && (
                    <tr>
                      <td colSpan="7" className="p-0 bg-slate-50 border-b border-slate-100">
                        <div className="px-5 py-3">
                          <MatchDetailsTable details={y.match_details} />
                        </div>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
