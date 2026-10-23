// ─── Main App ──────────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'thisyear',    label: String(THIS_YEAR) },
  { id: 'leaderboard', label: 'Leaderboard'     },
  { id: 'history',     label: 'History'         },
];

// Parse the URL hash into a route:
//   #player/42        -> { view: 'player', id: 42 }
//   #login            -> { view: 'login' }
//   #account          -> { view: 'account' }
//   #invite/<token>   -> { view: 'invite', token }
//   anything else     -> null (normal tabs)
function routeFromHash() {
  const h = window.location.hash;
  let m;
  if ((m = h.match(/^#player\/(\d+)$/)))        return { view: 'player', id: parseInt(m[1], 10) };
  if ((m = h.match(/^#invite\/([a-f0-9]{64})$/))) return { view: 'invite', token: m[1] };
  if (h === '#login')   return { view: 'login' };
  if (h === '#account') return { view: 'account' };
  return null;
}

function App() {
  const [tab, setTab]     = React.useState('thisyear');
  const [route, setRoute] = React.useState(routeFromHash);
  const session           = useSession();

  function go(hash) {
    window.history.pushState(null, '', hash);
    setRoute(routeFromHash());
  }

  // Back to the normal tabs: clear hash. replace=true keeps secrets (invite
  // tokens) out of the browser history.
  function home(replace) {
    const url = window.location.pathname + window.location.search;
    if (replace) window.history.replaceState(null, '', url);
    else         window.history.pushState(null, '', url);
    setRoute(null);
  }

  const openPlayer = id => go(`#player/${id}`);

  // Browser back/forward button support
  React.useEffect(() => {
    function onPop() { setRoute(routeFromHash()); }
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);

  // Custom navigate events fired by child views (leaderboard, etc.)
  React.useEffect(() => {
    function onNav(e) {
      const { view, id } = e.detail;
      if (view === 'player') openPlayer(id);
    }
    window.addEventListener('navigate', onNav);
    return () => window.removeEventListener('navigate', onNav);
  }, []);

  // Logged-in-only page: bounce to login when there's no session
  React.useEffect(() => {
    if (route && route.view === 'account' && !session) {
      window.history.replaceState(null, '', '#login');
      setRoute({ view: 'login' });
    }
  }, [route, session]);

  let body;
  if (!route)                       body = tab === 'thisyear'    ? <ThisYearView />
                                         : tab === 'leaderboard' ? <LeaderboardView />
                                         : tab === 'history'     ? <HistoryView />
                                         : null;
  else if (route.view === 'player')  body = <PlayerView playerId={route.id} onBack={() => home()} />;
  else if (route.view === 'login')   body = <LoginView onDone={() => { window.history.replaceState(null, '', '#account'); setRoute({ view: 'account' }); }} />;
  else if (route.view === 'invite')  body = <ActivateView token={route.token}
                                              onDone={() => { window.history.replaceState(null, '', '#account'); setRoute({ view: 'account' }); }} />;
  else if (route.view === 'account') body = <AccountView session={session} onOpenProfile={openPlayer}
                                              onLoggedOut={() => home(true)} />;

  return (
    <div className="min-h-screen bg-slate-100">
      <div className="bg-slate-900 text-white">
        <div className="max-w-3xl mx-auto px-4">
          <div className="flex items-center justify-between py-3">
            <button
              onClick={() => { home(); setTab('thisyear'); }}
              className="block">
              <img
                src="damsgaard-invitational-logo.jpeg"
                alt="Damsgaard Invitational"
                className="h-10 rounded"
              />
            </button>
            {session
              ? <button onClick={() => go('#account')}
                  className="flex items-center gap-2 text-sm text-slate-200 hover:text-white">
                  <span className={`w-2 h-2 rounded-full ${TEAM[session.account.team]?.bg || 'bg-slate-400'}`} />
                  {session.account.player_name.split(' ')[0]}
                </button>
              : <button onClick={() => go('#login')}
                  className="text-sm text-slate-300 hover:text-white">
                  Log in
                </button>}
          </div>
          {!route && (
            <div className="flex">
              {TABS.map(t => (
                <button key={t.id} onClick={() => setTab(t.id)}
                  className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
                    tab === t.id
                      ? 'border-white text-white'
                      : 'border-transparent text-slate-400 hover:text-slate-200'
                  }`}>
                  {t.label}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="py-4">{body}</div>
    </div>
  );
}

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<App />);
