<?php

/**
 * Social Display Shortcodes
 *
 * 4 cách hiển thị ảnh/video social (ảnh đại diện video TikTok…) cho trang web:
 *   [social_fan]       — fan/orbit xoay (giống Gruns)
 *   [social_marquee]   — 2 hàng chạy ngược chiều
 *   [social_collage]   — collage lệch tầng, thẻ nghiêng nhẹ
 *   [social_coverflow] — carousel 3D coverflow
 *
 * Nguồn media (theo thứ tự ưu tiên):
 *   1. atts `ids="12,34,56"`  — danh sách attachment ID cụ thể
 *   2. atts `urls="...,..."`  — danh sách URL ảnh trực tiếp
 *   3. Mặc định: query N media mới nhất có meta `_social_display = 1`
 *      (tool TikTok Research set meta này khi upload, kèm `_social_display_url` = link video gốc)
 *
 * Atts chung:
 *   heading   — tiêu đề (vd "1 triệu thành viên. Chúng tôi có mặt khắp nơi")
 *   bold      — đoạn con trong heading sẽ in đậm + đổi màu
 *   ids       — attachment IDs, phân tách bằng dấu phẩy
 *   urls      — URL ảnh, phân tách bằng dấu phẩy
 *   limit     — số media tối đa (mặc định 20)
 *   bg        — màu nền section
 *   accent    — màu chữ đậm (mặc định teal)
 *   instagram / tiktok / youtube / facebook — URL social (rỗng = ẩn icon)
 *
 * @package social-display-shortcodes
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lấy danh sách media item để render.
 *
 * @return array<int,array{is_video:bool,src:string,srcset:string,alt:string,link:string,poster:string}>
 */
if (!function_exists('social_display_get_items')) {
    function social_display_get_items(array $atts): array
    {
        $items = [];

        // 1. URL trực tiếp.
        $urls = array_filter(array_map('trim', explode(',', (string) ($atts['urls'] ?? ''))));
        foreach ($urls as $url) {
            $items[] = [
                'is_video' => (bool) preg_match('/\.(mp4|webm|mov)(\?|$)/i', $url),
                'src'      => $url,
                'srcset'   => '',
                'alt'      => '',
                'link'     => '',
                'poster'   => '',
            ];
        }
        if (!empty($items)) {
            return $items;
        }

        // 2. Attachment ID cụ thể, nếu không có thì query theo meta.
        $ids = array_filter(array_map('intval', explode(',', (string) ($atts['ids'] ?? ''))));
        if (empty($ids)) {
            $limit = max(1, (int) ($atts['limit'] ?? 20));
            $query = new WP_Query([
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'posts_per_page'         => $limit,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'meta_query'             => [
                    [
                        'key'   => '_social_display',
                        'value' => '1',
                    ],
                ],
            ]);
            $ids = $query->posts;
        }

        foreach ($ids as $id) {
            $src = wp_get_attachment_url($id);
            if (!$src) {
                continue;
            }
            $mime     = (string) get_post_mime_type($id);
            $is_video = strpos($mime, 'video/') === 0;
            $poster   = '';
            if ($is_video) {
                $poster = (string) get_the_post_thumbnail_url($id, 'large');
            }
            $items[] = [
                'is_video' => $is_video,
                'src'      => $src,
                'srcset'   => $is_video ? '' : (string) wp_get_attachment_image_srcset($id, 'large'),
                'alt'      => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
                'link'     => (string) get_post_meta($id, '_social_display_url', true),
                'poster'   => $poster,
            ];
        }

        return $items;
    }
}

/**
 * Render một thẻ media (ảnh hoặc video), bọc link nếu có.
 */
if (!function_exists('social_display_render_card')) {
    function social_display_render_card(array $it, string $extra_class = ''): string
    {
        if (!empty($it['is_video'])) {
            $inner = sprintf(
                '<video class="social-display__media" muted loop playsinline preload="metadata"%s><source src="%s"></video>',
                $it['poster'] !== '' ? ' poster="' . esc_url($it['poster']) . '"' : '',
                esc_url($it['src'])
            );
        } else {
            $inner = sprintf(
                '<img class="social-display__media" src="%s"%s alt="%s" loading="lazy" decoding="async">',
                esc_url($it['src']),
                $it['srcset'] !== '' ? ' srcset="' . esc_attr($it['srcset']) . '"' : '',
                esc_attr($it['alt'])
            );
        }

        if (!empty($it['link'])) {
            // Nếu là link video TikTok → gắn data-sd-video (id) để bấm phát inline qua lightbox.
            $vid = '';
            if (preg_match('~/video/(\d+)~', (string) $it['link'], $m)) {
                $vid = $m[1];
            }
            $inner = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="Xem video"%s>%s</a>',
                esc_url($it['link']),
                $vid !== '' ? ' data-sd-video="' . esc_attr($vid) . '"' : '',
                $inner
            );
        }

        $cls = 'social-display__card' . ($extra_class !== '' ? ' ' . $extra_class : '');
        return '<div class="' . esc_attr($cls) . '">' . $inner . '</div>';
    }
}

/**
 * Render khối tiêu đề + cụm icon social (dùng chung cho cả 4 template).
 */
if (!function_exists('social_display_render_header')) {
    function social_display_render_header(array $atts): string
    {
        $heading = trim((string) $atts['heading']);
        $bold    = trim((string) $atts['bold']);

        if ($heading === '') {
            $heading_html = '';
        } elseif ($bold !== '' && stripos($heading, $bold) !== false) {
            // Escape trước, rồi bọc <strong> quanh đoạn $bold (cũng đã escape) -> tránh chèn HTML thô.
            $heading_html = str_ireplace(
                esc_html($bold),
                '<strong>' . esc_html($bold) . '</strong>',
                esc_html($heading)
            );
        } else {
            $heading_html = esc_html($heading);
        }

        $links = [
            'instagram' => 'M17.5 6.5a5.6 5.6 0 100 11.2 5.6 5.6 0 000-11.2zm0 9.2a3.6 3.6 0 110-7.2 3.6 3.6 0 010 7.2zM23 6.9a1.3 1.3 0 11-2.6 0 1.3 1.3 0 012.6 0zM12 4.2h11A4.8 4.8 0 0127.8 9v11A4.8 4.8 0 0123 24.8H12A4.8 4.8 0 017.2 20V9A4.8 4.8 0 0112 4.2z',
            'tiktok'    => 'M20 4.2v8.1a6.4 6.4 0 11-5.5-6.3v3.1a3.4 3.4 0 102.4 3.2V4.2H20a5.5 5.5 0 004 3.8v3.1A8.5 8.5 0 0120 8.7',
            'youtube'   => 'M26 11.4a3 3 0 00-2.1-2.1C22 8.8 17.5 8.8 17.5 8.8s-4.5 0-6.4.5A3 3 0 009 11.4 31 31 0 008.6 17 31 31 0 009 22.6a3 3 0 002.1 2.1c1.9.5 6.4.5 6.4.5s4.5 0 6.4-.5a3 3 0 002.1-2.1A31 31 0 0026.4 17 31 31 0 0026 11.4zM15.5 19.7v-5.4l4.6 2.7-4.6 2.7z',
            'facebook'  => 'M19.5 17.5l.6-3.6h-3.5v-2.3c0-1 .5-2 2-2h1.6V6.4s-1.5-.3-2.9-.3c-2.9 0-4.8 1.8-4.8 5v2.8H9.3v3.6h3.2v8.7h3.9v-8.7h3.1z',
        ];

        $icons = '';
        foreach ($links as $key => $path) {
            $url = trim((string) ($atts[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $icons .= sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer" aria-label="%s"><svg viewBox="0 0 35 35" aria-hidden="true"><path d="%s"/></svg></a>',
                esc_url($url),
                esc_attr(ucfirst($key)),
                esc_attr($path)
            );
        }

        $html = '<div class="social-display__head">';
        if ($heading_html !== '') {
            $html .= '<h2 class="social-display__heading">' . $heading_html . '</h2>';
        }
        if ($icons !== '') {
            $html .= '<div class="social-display__links">' . $icons . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}

/**
 * In CSS + JS dùng chung — chỉ một lần cho cả trang.
 */
if (!function_exists('social_display_print_assets')) {
    function social_display_print_assets(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;

        ob_start(); ?>
<style id="social-display-css">
.social-display{--card-r:1.25rem;position:relative;overflow:hidden;width:100%;background:var(--sd-bg,#fdf6e3)}
.social-display *{box-sizing:border-box}
.social-display__head{position:relative;z-index:20;display:flex;flex-direction:column;align-items:center;gap:1rem;padding:2.5rem 1.5rem 0;text-align:center;pointer-events:none}
.social-display__head a{pointer-events:auto}
.social-display__heading{margin:0;max-width:52rem;font-family:'Oswald',sans-serif;font-weight:600;line-height:1.1;letter-spacing:2px;text-transform:uppercase;color:var(--sd-heading,#0a1912);font-size:clamp(1.8rem,4.5vw,3.4rem)}
.social-display__heading strong{color:var(--sd-accent,#0f766e);font-weight:600}
.social-display__links{display:flex;gap:.75rem;justify-content:center}
.social-display__links a{display:inline-flex;color:var(--sd-accent,#0f766e);transition:transform .25s,opacity .25s}
.social-display__links a:hover{transform:translateY(-2px);opacity:.8}
.social-display__links svg{width:2.1rem;height:2.1rem;fill:currentColor}
.social-display__card{position:relative;aspect-ratio:9/16;overflow:hidden;border-radius:var(--card-r);background:#0001;box-shadow:0 10px 30px -12px #0003}
.social-display__card a{display:block;width:100%;height:100%}
.social-display__media{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none}

/* ---------- FAN / ORBIT ---------- */
.social-display--fan{height:46rem}
@media(max-width:768px){.social-display--fan{height:36rem}}
.social-display__rotor{position:absolute;inset:0;z-index:10;transform-origin:50% 72%;transition:transform .9s cubic-bezier(.22,1,.36,1)}
.social-display__orbit{position:absolute;left:50%;top:72%;aspect-ratio:1;width:min(196vw,120rem);transform:translate(-50%,0) rotate(var(--a,0deg));transform-origin:center}
@media(max-width:768px){.social-display__orbit{width:min(440vw,120rem)}}
.social-display__orbit .social-display__card{position:absolute;left:50%;top:0;width:17rem;transform:translate(-50%,-50%)}
@media(max-width:768px){.social-display__orbit .social-display__card{width:11rem}}

/* ---------- MARQUEE ---------- */
.social-display--marquee{padding-bottom:3rem}
.social-display__rows{position:relative;z-index:10;display:flex;flex-direction:column;gap:1rem;margin-top:2rem;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}
.social-display__track{display:flex;gap:1rem;width:max-content;animation:ivs-scroll var(--dur,40s) linear infinite}
.social-display__row--rev .social-display__track{animation-direction:reverse}
.social-display__rows:hover .social-display__track{animation-play-state:paused}
.social-display__track .social-display__card{width:13rem;flex:0 0 auto}
@media(max-width:768px){.social-display__track .social-display__card{width:9rem}}
@keyframes ivs-scroll{to{transform:translateX(-50%)}}

/* ---------- COLLAGE ---------- */
.social-display--collage{padding-bottom:3rem}
.social-display__collage{position:relative;z-index:10;display:flex;flex-wrap:wrap;justify-content:center;align-items:flex-start;gap:1rem 1.25rem;max-width:80%;margin:2.5rem auto 0;padding:0 1.5rem}
.social-display__collage .social-display__card{width:13rem;transition:transform .35s}
@media(min-width:769px){.social-display[style*="--sd-cols"] .social-display__collage .social-display__card{width:calc((100% - (var(--sd-cols) - 1) * 1.25rem) / var(--sd-cols))}}
.social-display__collage .social-display__card:nth-child(odd){transform:rotate(-5deg) translateY(1.5rem)}
.social-display__collage .social-display__card:nth-child(even){transform:rotate(4deg)}
.social-display__collage .social-display__card:nth-child(3n){transform:rotate(-2deg) translateY(2.75rem)}
.social-display__collage .social-display__card:hover{transform:rotate(0) translateY(0) scale(1.04);z-index:5}
@media(max-width:768px){.social-display__collage .social-display__card{width:9rem}}

/* ---------- COVERFLOW ---------- */
.social-display--coverflow{padding-bottom:3.5rem}
.social-display__stage{position:relative;z-index:10;height:32rem;margin-top:2rem;perspective:1600px;overflow:hidden}
.social-display__cf-track{position:absolute;inset:0;transform-style:preserve-3d}
.social-display__cf-track .social-display__card{position:absolute;left:50%;top:50%;width:16rem;transition:transform .5s cubic-bezier(.22,1,.36,1),opacity .5s;will-change:transform;transform:translate(-50%,-50%) translateX(calc((var(--sd-i,1) - 1) * 9rem)) translateZ(calc((1 - var(--sd-ad,1)) * 9rem)) rotateY(calc((1 - var(--sd-i,1)) * 22deg));opacity:var(--sd-o,1);z-index:calc(100 - var(--sd-ad,1))}
.social-display__cf-track .social-display__card:nth-child(1){--sd-i:1;--sd-ad:0}.social-display__cf-track .social-display__card:nth-child(2){--sd-i:2;--sd-ad:1}.social-display__cf-track .social-display__card:nth-child(3){--sd-i:0;--sd-ad:1}.social-display__cf-track .social-display__card:nth-child(n+4){--sd-i:3;--sd-ad:3;--sd-o:0}
@media(max-width:768px){
.social-display--coverflow{padding-bottom:5rem;overflow:visible}
.social-display--coverflow .social-display__head{padding:2.25rem 1.25rem 0}
.social-display__stage{height:auto;margin-top:1.5rem;perspective:none;overflow-x:auto;overflow-y:visible;-webkit-overflow-scrolling:touch;scroll-snap-type:x mandatory;scroll-padding-inline:calc((100vw - min(68vw,220px)) / 2);padding:.25rem calc((100vw - min(68vw,220px)) / 2) 1rem;-ms-overflow-style:none;scrollbar-width:none;-webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent)}
.social-display__stage::-webkit-scrollbar{display:none}
.social-display__cf-track{position:relative;inset:auto;display:flex;gap:.85rem;width:max-content;transform-style:flat}
.social-display__cf-track .social-display__card{position:relative;left:auto;top:auto;flex:0 0 min(68vw,220px);width:min(68vw,220px);transform:none!important;opacity:1!important;z-index:auto!important;pointer-events:auto!important;scroll-snap-align:center;box-shadow:0 14px 32px -18px rgba(0,0,0,.45)}
}
.social-display__cf-nav{position:absolute;bottom:.5rem;left:50%;transform:translateX(-50%);z-index:15;display:flex;gap:.75rem}
.social-display__cf-nav button{width:2.75rem;height:2.75rem;border-radius:999px;border:none;cursor:pointer;font-size:1.2rem;line-height:1;background:var(--sd-accent,#0f766e);color:#fff;transition:transform .2s,opacity .2s}
.social-display__cf-nav button:hover{transform:scale(1.08)}
@media(max-width:768px){.social-display__cf-nav{position:relative;bottom:auto;left:auto;transform:none;justify-content:center;margin-top:.35rem}}
.social-display__card a[data-sd-video]{cursor:pointer}
.social-display__card a[data-sd-video]::after{content:"";position:absolute;inset:0;background:no-repeat center/3rem url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Ccircle cx='32' cy='32' r='30' fill='%23000' opacity='.45'/%3E%3Cpath d='M26 22l18 10-18 10z' fill='%23fff'/%3E%3C/svg%3E");opacity:0;transition:opacity .25s;pointer-events:none}
.social-display__card:hover a[data-sd-video]::after{opacity:1}

/* ---------- LIGHTBOX (phát video TikTok inline) ---------- */
.sd-lightbox{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center}
.sd-lightbox[hidden]{display:none}
.sd-lightbox__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.78)}
.sd-lightbox__inner{position:relative;z-index:1;width:min(94vw,400px);aspect-ratio:9/16;max-height:92vh;background:#000;border-radius:1rem;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.55)}
.sd-lightbox__frame,.sd-lightbox__frame iframe{width:100%;height:100%;border:0;display:block}
.sd-lightbox__close{position:absolute;top:.5rem;right:.5rem;z-index:2;width:2.2rem;height:2.2rem;border:none;border-radius:999px;background:rgba(0,0,0,.55);color:#fff;font-size:1.4rem;line-height:1;cursor:pointer}
.sd-lightbox__close:hover{background:rgba(0,0,0,.8)}
</style>
<div class="sd-lightbox" id="sd-lightbox" hidden>
  <div class="sd-lightbox__backdrop" data-sd-close></div>
  <div class="sd-lightbox__inner">
    <button type="button" class="sd-lightbox__close" data-sd-close aria-label="Đóng">&times;</button>
    <div class="sd-lightbox__frame"></div>
  </div>
</div>
<script id="social-display-js">
(function(){
  function init(){
    /* FAN: dàn các thẻ thành vòng cung + tự xoay đưa thẻ qua đỉnh */
    document.querySelectorAll('.social-display--fan').forEach(function(sec){
      var rotor=sec.querySelector('.social-display__rotor');
      var orbits=[].slice.call(sec.querySelectorAll('.social-display__orbit'));
      if(!rotor||!orbits.length)return;
      var step=15,start=-((orbits.length-1)/2)*step;
      orbits.forEach(function(o,i){o.style.setProperty('--a',(start+i*step)+'deg');});
      var iv=parseFloat(sec.getAttribute('data-interval')||'3')*1000;
      var ang=0,paused=false;
      sec.addEventListener('mouseenter',function(){paused=true;});
      sec.addEventListener('mouseleave',function(){paused=false;});
      setInterval(function(){if(paused)return;ang-=step;rotor.style.transform='rotate('+ang+'deg)';},iv);
    });

    /* MARQUEE: nhân đôi track để cuộn liền mạch */
    document.querySelectorAll('.social-display--marquee .social-display__track').forEach(function(t){
      t.innerHTML+=t.innerHTML;
    });

    /* COVERFLOW: xếp 3D, prev/next + auto */
    function initCoverflow(root){
      var scope=root || document;
      var sections=[].slice.call(scope.querySelectorAll('.social-display--coverflow'));
      if(scope.nodeType===1&&scope.classList.contains('social-display--coverflow'))sections.unshift(scope);
      sections.forEach(function(sec){
        if(sec.dataset.sdCoverflowReady==='1')return;
        var track=sec.querySelector('.social-display__cf-track');
        var cards=track?[].slice.call(track.children).filter(function(c){return c.classList.contains('social-display__card');}):[];
        if(!track||!cards.length)return;
        sec.dataset.sdCoverflowReady='1';
        var cur=0;
        function isMobile(){
          return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
        }
        function clearInlineLayout(){
          cards.forEach(function(c){
            c.style.transform='';
            c.style.opacity='';
            c.style.pointerEvents='';
            c.style.zIndex='';
          });
        }
        function scrollMobile(dir){
          var stage=sec.querySelector('.social-display__stage');
          if(!stage)return;
          var gap=parseFloat(window.getComputedStyle(track).gap||'0')||0;
          var cardWidth=cards[0].getBoundingClientRect().width;
          var distance=cardWidth+gap;
          var atStart=stage.scrollLeft<=4;
          var atEnd=stage.scrollLeft+stage.clientWidth>=stage.scrollWidth-4;
          if(dir>0&&atEnd){stage.scrollTo({left:0,behavior:'smooth'});return;}
          if(dir<0&&atStart){stage.scrollTo({left:stage.scrollWidth,behavior:'smooth'});return;}
          stage.scrollBy({left:dir*distance,behavior:'smooth'});
        }
        function layout(){
          if(isMobile()){clearInlineLayout();return;}
          cards.forEach(function(c,i){
            var raw=i-cur;
            var half=cards.length/2;
            var d=((raw+half)%cards.length)-half;
            var ad=Math.abs(d);
            c.style.transform='translate(-50%,-50%) translateX('+(d*9)+'rem) translateZ('+(-ad*9)+'rem) rotateY('+(d*-22)+'deg)';
            c.style.opacity=ad>2?'0':'1';
            c.style.pointerEvents=ad>2?'none':'auto';
            c.style.zIndex=String(100-Math.round(ad));
          });
        }
        sec.querySelectorAll('.social-display__cf-nav button').forEach(function(b){
          b.addEventListener('click',function(){
            if(isMobile()){scrollMobile(b.dataset.dir==='next'?1:-1);return;}
            cur=(cur+(b.dataset.dir==='next'?1:-1)+cards.length)%cards.length;layout();
          });
        });
        layout();
        window.addEventListener('resize',layout,{passive:true});
        var paused=false;
        sec.addEventListener('mouseenter',function(){paused=true;});
        sec.addEventListener('mouseleave',function(){paused=false;});
        setInterval(function(){
          if(paused||document.hidden)return;
          if(isMobile()){scrollMobile(1);return;}
          cur=(cur+1)%cards.length;layout();
        },4000);
      });
    }
    initCoverflow(document);
    if(!window.socialDisplayCoverflowObserver){
      window.socialDisplayCoverflowObserver=new MutationObserver(function(nodes){
        nodes.forEach(function(m){
          m.addedNodes.forEach(function(n){
            if(n.nodeType===1)initCoverflow(n);
          });
        });
      });
      window.socialDisplayCoverflowObserver.observe(document.documentElement,{childList:true,subtree:true});
    }

    /* VIDEO: autoplay khi hover, dừng khi rời */
    document.querySelectorAll('.social-display video').forEach(function(v){
      var card=v.closest('.social-display__card');
      if(!card)return;
      card.addEventListener('mouseenter',function(){v.play().catch(function(){});});
      card.addEventListener('mouseleave',function(){v.pause();v.currentTime=0;});
    });

    /* LIGHTBOX: bấm thẻ có data-sd-video → phát player TikTok nhúng ngay trên trang */
    var lb=document.getElementById('sd-lightbox');
    if(lb && !lb.dataset.bound){
      lb.dataset.bound='1';
      /* Đưa lightbox ra body: tránh bị neo vào ancestor có transform (vd .anc-fade-in)
         khiến position:fixed lệch về giữa widget thay vì giữa màn hình. */
      if(lb.parentNode!==document.body){document.body.appendChild(lb);}
      var frame=lb.querySelector('.sd-lightbox__frame');
      function openLB(id){
        frame.innerHTML='<iframe src="https://www.tiktok.com/player/v1/'+id+'?autoplay=1&loop=1&rel=0&description=0&music_info=0" allow="autoplay;encrypted-media;fullscreen" allowfullscreen></iframe>';
        lb.hidden=false; document.documentElement.style.overflow='hidden';
      }
      function closeLB(){ frame.innerHTML=''; lb.hidden=true; document.documentElement.style.overflow=''; }
      document.addEventListener('click',function(e){
        var a=e.target.closest('a[data-sd-video]');
        if(a){ e.preventDefault(); openLB(a.getAttribute('data-sd-video')); return; }
        if(e.target.closest('[data-sd-close]')) closeLB();
      });
      document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&!lb.hidden) closeLB(); });
    }
  }
  if(document.readyState!=='loading')init();else document.addEventListener('DOMContentLoaded',init);
})();
</script>
<?php
        return (string) ob_get_clean();
    }
}

/**
 * Atts mặc định + style nền dùng chung.
 *
 * @return array{0:array,1:array} [atts đã merge, items]
 */
if (!function_exists('social_display_prepare')) {
    function social_display_prepare(array $atts, string $tag): array
    {
        $atts = shortcode_atts([
            'heading'   => '',
            'bold'      => '',
            'ids'       => '',
            'urls'      => '',
            'limit'     => 20,
            'columns'   => 0,
            'bg'        => '#fdf6e3',
            'accent'    => '#0f766e',
            'heading_color' => '#0a1912',
            'padding'   => '',
            'instagram' => '',
            'tiktok'    => '',
            'youtube'   => '',
            'facebook'  => '',
            'interval'  => 3,
        ], $atts, $tag);

        return [$atts, social_display_get_items($atts)];
    }
}

if (!function_exists('social_display_sanitize_css_spacing')) {
    function social_display_sanitize_css_spacing(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $value);
        if ($parts === false || count($parts) > 4) {
            return '';
        }

        foreach ($parts as $part) {
            if (!preg_match('/^(?:0|-?\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|vmin|vmax|ch|ex|pt|pc|cm|mm|in))$/i', $part)) {
                return '';
            }
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('social_display_style_vars')) {
    function social_display_style_vars(array $atts): string
    {
        $vars = sprintf(
            '--sd-bg:%s;--sd-accent:%s;--sd-heading:%s',
            esc_attr($atts['bg']),
            esc_attr($atts['accent']),
            esc_attr($atts['heading_color'])
        );

        // Số cột cố định cho collage (0 = tự động theo chiều rộng).
        $cols = (int) ($atts['columns'] ?? 0);
        if ($cols > 0) {
            $vars .= ';--sd-cols:' . $cols;
        }

        $padding = social_display_sanitize_css_spacing((string) ($atts['padding'] ?? ''));
        if ($padding !== '') {
            $vars .= ';padding:' . esc_attr($padding);
        }

        return $vars;
    }
}

/* ============================ SHORTCODES ============================ */

/**
 * Build phần <section> cho một kiểu hiển thị (dùng chung cho cả shortcode đơn lẫn responsive).
 *
 * @param string $variant fan | marquee | collage | coverflow
 */
if (!function_exists('social_display_build_section')) {
    function social_display_build_section(string $variant, array $atts, array $items): string
    {
        $header = social_display_render_header($atts);
        $style  = social_display_style_vars($atts);

        $cards = static function (array $list): string {
            $out = '';
            foreach ($list as $it) {
                $out .= social_display_render_card($it);
            }
            return $out;
        };

        if ($variant === 'fan') {
            $orbits = '';
            foreach ($items as $it) {
                $orbits .= '<div class="social-display__orbit">' . social_display_render_card($it) . '</div>';
            }
            return sprintf(
                '<section class="social-display social-display--fan" style="%s" data-interval="%s">%s<div class="social-display__rotor">%s</div></section>',
                $style,
                esc_attr((string) $atts['interval']),
                $header,
                $orbits
            );
        }

        if ($variant === 'marquee') {
            $half = (int) ceil(count($items) / 2);
            $rowA = array_slice($items, 0, $half);
            $rowB = array_slice($items, $half) ?: $rowA;
            return sprintf(
                '<section class="social-display social-display--marquee" style="%s">%s<div class="social-display__rows">'
                    . '<div class="social-display__row"><div class="social-display__track">%s</div></div>'
                    . '<div class="social-display__row social-display__row--rev"><div class="social-display__track">%s</div></div>'
                    . '</div></section>',
                $style,
                $header,
                $cards($rowA),
                $cards($rowB)
            );
        }

        if ($variant === 'coverflow') {
            return sprintf(
                '<section class="social-display social-display--coverflow" style="%s">%s'
                    . '<div class="social-display__stage"><div class="social-display__cf-track">%s</div></div>'
                    . '<div class="social-display__cf-nav"><button type="button" data-dir="prev" aria-label="Trước">‹</button>'
                    . '<button type="button" data-dir="next" aria-label="Sau">›</button></div></section>',
                $style,
                $header,
                $cards($items)
            );
        }

        // collage (mặc định)
        return sprintf(
            '<section class="social-display social-display--collage" style="%s">%s<div class="social-display__collage">%s</div></section>',
            $style,
            $header,
            $cards($items)
        );
    }
}

if (!function_exists('social_display_variant_shortcode')) {
    /**
     * Factory cho 4 shortcode đơn — mỗi cái cố định một kiểu.
     */
    function social_display_variant_shortcode(string $variant, $atts, string $tag): string
    {
        [$atts, $items] = social_display_prepare((array) $atts, $tag);
        if (empty($items)) {
            return '';
        }
        return social_display_print_assets() . social_display_build_section($variant, $atts, $items);
    }
}

add_shortcode('social_fan', fn($a = []) => social_display_variant_shortcode('fan', $a, 'social_fan'));
add_shortcode('social_marquee', fn($a = []) => social_display_variant_shortcode('marquee', $a, 'social_marquee'));
add_shortcode('social_collage', fn($a = []) => social_display_variant_shortcode('collage', $a, 'social_collage'));
add_shortcode('social_coverflow', fn($a = []) => social_display_variant_shortcode('coverflow', $a, 'social_coverflow'));

/**
 * Shortcode responsive: chọn kiểu khác nhau cho desktop vs mobile.
 *   [social_display desktop="collage" mobile="coverflow" breakpoint="768"]
 * Nếu desktop == mobile thì render một lần. Khác nhau thì render cả hai,
 * ẩn/hiện bằng media query theo breakpoint (đường cắt mặc định 768px).
 */
if (!function_exists('social_display_responsive_shortcode')) {
    function social_display_responsive_shortcode($atts = []): string
    {
        $raw = (array) $atts;
        [$prepared, $items] = social_display_prepare($raw, 'social_display');
        if (empty($items)) {
            return '';
        }

        $valid = ['fan', 'marquee', 'collage', 'coverflow'];
        $desktop = in_array($raw['desktop'] ?? '', $valid, true) ? $raw['desktop'] : 'collage';
        $mobile  = in_array($raw['mobile'] ?? '', $valid, true) ? $raw['mobile'] : 'coverflow';
        $bp = max(320, (int) ($raw['breakpoint'] ?? 768));

        if ($desktop === $mobile) {
            return social_display_print_assets() . social_display_build_section($desktop, $prepared, $items);
        }

        $id = 'sd-resp-' . uniqid();
        $inline = sprintf(
            '<style>@media(max-width:%1$dpx){#%2$s>.sd-resp__d{display:none}}@media(min-width:%3$dpx){#%2$s>.sd-resp__m{display:none}}</style>',
            $bp,
            esc_attr($id),
            $bp + 1
        );

        return social_display_print_assets() . $inline . sprintf(
            '<div class="sd-resp" id="%s"><div class="sd-resp__d">%s</div><div class="sd-resp__m">%s</div></div>',
            esc_attr($id),
            social_display_build_section($desktop, $prepared, $items),
            social_display_build_section($mobile, $prepared, $items)
        );
    }
}
add_shortcode('social_display', 'social_display_responsive_shortcode');

/**
 * Đăng ký meta cho attachment để REST API (tool TikTok Research) ghi được
 * `_social_display` (cờ nguồn) và `_social_display_url` (link video gốc) — meta có
 * underscore là protected nên phải register mới set được qua REST.
 */
if (!function_exists('social_display_register_media_meta')) {
    function social_display_register_media_meta(): void
    {
        foreach (['_social_display', '_social_display_url'] as $meta_key) {
            register_post_meta('attachment', $meta_key, [
                'type'          => 'string',
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => static function (): bool {
                    return current_user_can('upload_files');
                },
            ]);
        }
    }
    add_action('init', 'social_display_register_media_meta');
}
