<header class="navbar-custom">
  <div class="navbar-left">
    <button type="button" class="menu-toggle" aria-label="Toggle navigation menu">
      <i class="ph ph-list"></i>
    </button>

    <div class="app-identity">
      <p class="app-identity-title">Fleet &amp; Transportation Management</p>
      <span class="app-identity-subtitle">Hospital Operations Suite</span>
    </div>
  </div>

  <div class="navbar-center">
    <div class="search-box">
      <i class="ph ph-magnifying-glass" aria-hidden="true"></i>

      <input
        type="text"
        placeholder="Search vehicles, drivers, reservations..."
        aria-label="Global search"
      />
    </div>
  </div>

  <div class="navbar-right">
    <button type="button" class="icon-btn" aria-label="Notifications">
      <i class="ph ph-bell" aria-hidden="true"></i>
    </button>

    <button type="button" class="icon-btn" aria-label="Messages">
      <i class="ph ph-envelope-simple" aria-hidden="true"></i>
    </button>
  </div>
</header>

<script>
  window.FLEET_NAV_RBAC = {
      dashboard:
          @json(auth()->user()?->canViewModule('dashboard') ?? false),

      vehicles:
          @json(auth()->user()?->canViewModule('vehicles') ?? false),

      reservations:
          @json(auth()->user()?->canViewModule('reservations') ?? false),

      dispatch:
          @json(auth()->user()?->canViewModule('dispatch') ?? false),

      drivers:
          @json(auth()->user()?->canViewModule('drivers') ?? false),

      driverPerformance:
          @json(auth()->user()?->canViewModule('driver_performance') ?? false),

      maintenance:
          @json(auth()->user()?->canViewModule('maintenance') ?? false),

      fuel:
          @json(auth()->user()?->canViewModule('fuel') ?? false),

      routes:
          @json(auth()->user()?->canViewModule('route_planning') ?? false),

      cost:
          @json(auth()->user()?->canViewModule('cost_analysis') ?? false),

      reports:
          @json(auth()->user()?->canViewModule('reports') ?? false),

      settings:
          @json(auth()->user()?->canViewModule('settings') ?? false),

      profile:
          @json(auth()->user()?->canViewModule('profile') ?? false)
  };
</script>
