<?php
	defined('ABSPATH') or die( 'Access denied!' ); // Avoid direct file request
?>

<div class="templately-admin-body">
    <div id="templatelyAdmin">
        <?php
            // Server-rendered full-page skeleton (no logo). Shown only until the React
            // app mounts into #templatelyAdmin and replaces it, so a refresh shows a
            // complete skeleton instantly — top nav, header, filter toolbar and the
            // card grid — instead of a logo flash or a blank gap. Layout classes mirror
            // the real components (TopNavigation, NewHeader, Filter, TemplateGridSkeleton);
            // the grey placeholder blocks are sized with inline styles so they render
            // correctly against the already-compiled admin CSS (no rebuild required).
        ?>
        <div class="templately-admin-skeleton fixed inset-0 h-screen w-screen flex flex-col bg-surface-primary overflow-hidden" aria-busy="true" aria-hidden="true">

            <?php // Top navigation — mirrors TopNavigation.js (h-9) ?>
            <div class="shrink-0 h-9 flex items-center justify-between px-4 md:px-6 lg:px-7 border-b border-solid border-border-secondary">
                <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:12rem;height:.85rem;"></span>
                <div class="flex items-center gap-4">
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:6.5rem;height:.85rem;"></span>
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:4.5rem;height:.85rem;"></span>
                </div>
            </div>

            <?php // Header — mirrors NewHeader (h-11 md:h-18): logo + switcher | search | support + avatar | close ?>
            <div class="shrink-0 w-full flex items-center gap-x-2 h-11 md:h-18 border-b border-solid border-border-secondary">
                <div class="flex items-center gap-3 ps-4 md:ps-6 lg:ps-7 shrink-0">
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:9999px;width:2rem;height:2rem;"></span>
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:5rem;height:1rem;"></span>
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.5rem;width:7rem;height:2rem;"></span>
                </div>
                <div class="grow hidden md:!flex justify-center items-center lg:pe-[2.78%]">
                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:9999px;width:100%;max-width:37.5rem;height:2.75rem;"></span>
                </div>
                <div class="ml-auto md:ml-0 flex items-center self-stretch">
                    <div class="hidden md:!flex items-center h-full pe-3 gap-x-3">
                        <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:6rem;height:.85rem;"></span>
                        <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:9999px;width:2rem;height:2rem;"></span>
                    </div>
                    <div class="h-11 md:h-18 w-11 md:w-18 shrink-0 flex items-center justify-center border-s border-solid border-border-secondary">
                        <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:1.5rem;height:1.5rem;"></span>
                    </div>
                </div>
            </div>

            <?php // Outlet content — filter toolbar + card grid ?>
            <div class="grow overflow-hidden flex flex-col">

                <?php // Filter toolbar — mirrors FilterBarSkeleton.js ?>
                <div class="shrink-0 w-full flex pb-4 md:pb-6 lg:pb-8">
                    <div class="px-4 w-full md:px-6 lg:px-7 py-2 h-18 shrink-0 flex gap-x-4 md:gap-x-6 items-center">
                        <span class="motion-safe:animate-pulse motion-reduce:animate-none shrink-0" style="display:block;background:#F1F1F7;border-radius:9999px;width:6rem;height:2.5rem;"></span>
                        <div class="hidden md:!flex items-center gap-x-2 overflow-hidden">
                            <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:4rem;height:.75rem;"></span>
                            <?php foreach ( array( '7rem', '5rem', '6rem', '7rem', '6rem', '5rem' ) as $chip_w ) : ?>
                                <span class="motion-safe:animate-pulse motion-reduce:animate-none shrink-0" style="display:block;background:#F1F1F7;border-radius:9999px;width:<?php echo esc_attr( $chip_w ); ?>;height:2.5rem;"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="ms-auto flex items-center gap-3 md:gap-4 shrink-0">
                            <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:9999px;width:3.75rem;height:2.5rem;"></span>
                            <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:9999px;width:6.25rem;height:2.5rem;"></span>
                        </div>
                    </div>
                </div>

                <?php // Card grid — mirrors TemplateGridSkeleton.js ?>
                <div class="grow flex flex-col px-4 md:px-6 lg:px-7 pb-8 overflow-hidden">
                    <div class="grid grid-cols-auto-80 gap-x-4 md:gap-x-6 gap-y-8">
                        <?php for ( $i = 0; $i < 12; $i++ ) : ?>
                            <div class="w-full flex flex-col gap-3.5 mb-0">
                                <div class="w-full bg-surface-secondary p-3 rounded-sm border border-solid border-border-secondary">
                                    <div class="w-full motion-safe:animate-pulse motion-reduce:animate-none" style="background:#F1F1F7;aspect-ratio:100/114.65;"></div>
                                </div>
                                <div class="flex flex-col gap-3">
                                    <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:75%;height:.875rem;"></span>
                                    <span class="w-full flex items-center gap-2">
                                        <span class="motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:6rem;height:2rem;"></span>
                                        <span class="ms-auto motion-safe:animate-pulse motion-reduce:animate-none" style="display:block;background:#F1F1F7;border-radius:.375rem;width:3rem;height:1rem;"></span>
                                    </span>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
