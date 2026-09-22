<?php $active = $active ?? ''; ?>
<nav class="site-nav">
    <div class="container">
        <a class="nav-brand" href="/portal">
            <span class="brand-mark">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0b1220" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 1 1-9-9"/><path d="M21 3v6h-6"/>
                </svg>
            </span>
            PlasticID<span style="color:#34d399">API</span>
        </a>

        <button class="nav-toggle" aria-controls="portalNav" aria-expanded="false" aria-label="Toggle navigation">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <div class="nav-links" id="portalNav">
            <a class="nav-link-item <?= $active === 'home' ? 'active' : '' ?>" href="/portal">Home</a>
            <a class="nav-link-item <?= $active === 'methodology' ? 'active' : '' ?>" href="/portal/methodology">Methodology</a>
            <a class="nav-link-item <?= $active === 'docs' ? 'active' : '' ?>" href="/portal/docs">API Docs</a>
            <a class="nav-link-item <?= $active === 'playground' ? 'active' : '' ?>" href="/portal/playground">Playground</a>
            <a class="nav-link-item <?= $active === 'request' ? 'active' : '' ?>" href="/portal/request">Get API Key</a>
            <a class="nav-ghost" href="http://localhost:8000/docs" target="_blank" rel="noopener">Swagger</a>
            <a class="nav-cta" href="/portal/playground">Try It Free</a>
        </div>
    </div>
</nav>