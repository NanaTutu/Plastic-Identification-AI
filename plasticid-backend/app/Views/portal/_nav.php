<?php
$active = $active ?? '';
$apiDocsUrl = rtrim((string) (getenv('FASTAPI_PUBLIC_URL') ?: 'https://plasticid.ohene.top'), '/') . '/docs';
?>
<nav class="site-nav">
    <div class="container">
        <a class="brand" href="/portal" aria-label="PlasticID home">
            <span class="brand-mark">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 1 1-9-9"/><path d="M21 3v6h-6"/>
                </svg>
            </span>
            PlasticID
        </a>

        <button class="nav-toggle" aria-controls="portalNav" aria-expanded="false" aria-label="Toggle navigation">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
        </button>

        <div class="nav-links" id="portalNav">
            <a class="nav-link <?= $active === 'home' ? 'active' : '' ?>" href="/portal">Home</a>
            <a class="nav-link <?= $active === 'methodology' ? 'active' : '' ?>" href="/portal/methodology">Methodology</a>
            <a class="nav-link <?= $active === 'docs' ? 'active' : '' ?>" href="/portal/docs">API Docs</a>
            <a class="nav-link <?= $active === 'playground' ? 'active' : '' ?>" href="/portal/playground">Playground</a>
            <a class="nav-utility" href="<?= esc($apiDocsUrl) ?>" target="_blank" rel="noopener">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>
                Swagger
            </a>
            <a class="btn btn-primary nav-cta" href="/portal/request">Get API key</a>
        </div>
    </div>
</nav>