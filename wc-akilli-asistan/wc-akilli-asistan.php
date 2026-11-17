<?php
/**
 * Plugin Name:       WC Akıllı Mağaza Asistanı
 * Plugin URI:        https://ornek-site.com/wc-akilli-asistan
 * Description:       Seviye 1-2-3 adım adım geliştirilen modern WordPress ve WooCommerce eklentisi.
 * Version:           3.0.0
 * Author:            Kıdemli PHP Geliştirici
 * Author URI:        https://ornek-site.com
 * License:           GPL v2 or later
 * Text Domain:       wc-akilli-asistan
 */

// Modern PHP standardı: Katı tip kontrolünü (Strict Types) aktif ediyoruz.
declare(strict_types=1);

// GÜVENLİK KONTROLÜ: Dosyaya tarayıcıdan doğrudan (URL ile) erişilmesini engeller.
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Doğrudan erişim engellendi.' );
}

/**
 * Ana Eklenti Sınıfımız (OOP Mimarisi - Seviye 1 + Seviye 2 + Seviye 3).
 */
final class WC_Akilli_Asistan {

    /**
     * Eklenti versiyonunu, kampanya ve API sabitlerini tanımlıyoruz.
     */
    public const VERSION                 = '3.0.0';
    public const MIN_SEPET_TUTARI        = 1000.0; // İndirim için gereken minimum sepet tutarı (TL)
    public const MIN_URUN_ADEDI          = 3;      // Veya sepette olması gereken minimum ürün adedi
    public const VARSAYILAN_INDIRIM_ORAN = 0.10;   // %10 İndirim oranı

    // SEVİYE 3 SABİTLERİ (Dış REST API ve Önbellek Anahtarları)
    // Open-Meteo: Ücretsiz, API anahtarı gerektirmeyen gerçek hava durumu REST API'si (İstanbul koordinatları)
    public const HAVA_DURUMU_API_URL     = 'https://api.open-meteo.com/v1/forecast?latitude=41.0082&longitude=28.9784&current_weather=true';
    // JSONPlaceholder: Ücretsiz, gerçek POST isteği kabul eden test CRM/Webhook REST API'si
    public const CRM_WEBHOOK_API_URL     = 'https://jsonplaceholder.typicode.com/posts';
    // WordPress Transient (Önbellek) anahtarı
    public const TRANSIENT_HAVA_ANAHTARI = 'wc_asistan_depo_hava_durumu';

    /**
     * Kurucu Metot (Constructor).
     * Tüm WordPress ve WooCommerce kancalarımızı (hooks) burada kayıt altına alırız.
     */
    public function __construct() {
        // =====================================================================
        // SEVİYE 1 KANCALARI (Temel WordPress Hook Mimarisi)
        // =====================================================================
        add_action( 'admin_menu', [ $this, 'yonetici_menusu_ekle' ] );
        add_action( 'wp_footer', [ $this, 'site_alt_kisim_mesaji_yazdir' ] );
        add_filter( 'admin_footer_text', [ $this, 'admin_alt_bilgiyi_filtrele' ], 10, 1 );
        add_filter( 'wc_asistan_dinamik_footer_metni', [ $this, 'footer_metnine_kampanya_ekle' ], 10, 2 );

        // =====================================================================
        // SEVİYE 2 & 3 KANCALARI (WooCommerce Sepet + Dış API / Webhook)
        // =====================================================================
        if ( $this->woocommerce_aktif_mi() ) {
            // --- SEVİYE 2: Sepet Müdahalesi ---
            add_action( 'woocommerce_cart_calculate_fees', [ $this, 'sepete_ozel_indirim_uygula' ], 10, 1 );
            add_action( 'woocommerce_before_cart', [ $this, 'sepet_ust_bilgi_mesaji_goster' ] );
            add_filter( 'woocommerce_cart_item_name', [ $this, 'sepet_urun_ismine_rozet_ekle' ], 10, 3 );

            // --- SEVİYE 3: Dış REST API (wp_remote_get) & CRM Webhook (wp_remote_post) ---
            // 1. Ödeme (Checkout) sayfasının en üstünde dış API'den gelen canlı lojistik/hava durumunu göster.
            add_action( 'woocommerce_before_checkout_form', [ $this, 'odeme_sayfasinda_api_bilgisi_goster' ], 5 );

            // 2. Sepet sayfasında "Ödeme Sayfasına Git" butonunun hemen üstünde de canlı kargo durumunu göster.
            add_action( 'woocommerce_proceed_to_checkout', [ $this, 'odeme_sayfasinda_api_bilgisi_goster' ], 5 );

            // 3. Sipariş tamamlandığında (Teşekkürler sayfasında) siparişi dış CRM Webhook'una POST et.
            add_action( 'woocommerce_thankyou', [ $this, 'siparisi_dis_crm_webhookuna_gonder' ], 10, 1 );
        } else {
            add_action( 'admin_notices', [ $this, 'woocommerce_eksik_uyarisi_goster' ] );
        }
    }

    /**
     * Sitede WooCommerce'in aktif olup olmadığını kontrol eden yardımcı (helper) metot.
     */
    private function woocommerce_aktif_mi(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * [ADMIN NOTICE CALLBACK] WooCommerce kurulu/aktif değilse admin panelinde sarı uyarı kutusu çıkarır.
     */
    public function woocommerce_eksik_uyarisi_goster(): void {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>⚠️ WC Akıllı Mağaza Asistanı:</strong> 
                Sepet ve API özelliklerinin çalışabilmesi için lütfen <strong>WooCommerce</strong> eklentisini kurup etkinleştirin.
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // SEVİYE 1 METOTLARI
    // =========================================================================

    public function yonetici_menusu_ekle(): void {
        add_menu_page(
            'Akıllı Asistan Yönetim Paneli',
            'Akıllı Asistan',
            'manage_options',
            'wc-akilli-asistan',
            [ $this, 'yonetici_sayfasi_icerigi' ],
            'dashicons-superhero',
            56
        );
    }

    /**
     * [ADMIN SAYFASI GÖRÜNÜMÜ] Seviye 1, 2 ve 3 durumlarını ve canlı API testini gösterir.
     */
    public function yonetici_sayfasi_icerigi(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Eğer yönetici "API Önbelleğini Temizle ve Yeniden Çek" butonuna bastıysa (Nonce güvenlik kontrolü ile):
        $crm_test_sonucu = null;
        if ( isset( $_POST['wc_asistan_aksiyon'] ) && check_admin_referer( 'wc_asistan_admin_nonce' ) ) {
            $aksiyon = sanitize_text_field( wp_unslash( $_POST['wc_asistan_aksiyon'] ) );

            if ( 'cache_temizle' === $aksiyon ) {
                delete_transient( self::TRANSIENT_HAVA_ANAHTARI );
            } elseif ( 'crm_test_gonder' === $aksiyon ) {
                $crm_test_sonucu = $this->ornek_crm_webhook_testi_yap();
            }
        }

        $mevcut_kullanici = wp_get_current_user();
        $wc_durum         = $this->woocommerce_aktif_mi();
        $canli_hava_veri  = $this->dis_apiden_depo_hava_durumu_getir();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?> (v<?php echo esc_html( self::VERSION ); ?>)</h1>
            
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong>Tebrikler!</strong> 
                    Merhaba <code><?php echo esc_html( $mevcut_kullanici->display_name ); ?></code>, 
                    Eklentinin <strong>Seviye 1, Seviye 2 ve Seviye 3 (Dış API & Webhook)</strong> modüllerinin tamamı aktif!
                </p>
            </div>

            <?php if ( is_array( $crm_test_sonucu ) ) : ?>
                <div class="notice <?php echo $crm_test_sonucu['basarili'] ? 'notice-info' : 'notice-error'; ?> is-dismissible">
                    <p>
                        <strong>📡 CRM Webhook (`wp_remote_post`) Test Sonucu:</strong>
                        <?php echo esc_html( $crm_test_sonucu['mesaj'] ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                <!-- Seviye 1 Özeti -->
                <div class="card" style="flex: 1; min-width: 280px; padding: 15px 25px; margin-top: 0;">
                    <h2>✅ Seviye 1: Temel Hook Mimarisi</h2>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><strong>Action (<code>admin_menu</code>):</strong> Yönetim sayfası.</li>
                        <li><strong>Action (<code>wp_footer</code>):</strong> Ön yüz alt bilgi barı.</li>
                        <li><strong>Filter (<code>admin_footer_text</code>):</strong> Admin imzası.</li>
                        <li><strong>Filter (<code>wc_asistan_dinamik_footer_metni</code>):</strong> Kampanya filtresi.</li>
                    </ul>
                </div>

                <!-- Seviye 2 Özeti -->
                <div class="card" style="flex: 1; min-width: 280px; padding: 15px 25px; margin-top: 0; border-left: 4px solid #7f54b3;">
                    <h2>🛒 Seviye 2: WooCommerce Sepet</h2>
                    <p>
                        <strong>WooCommerce:</strong> 
                        <?php echo $wc_durum ? '<span style="color:#00a32a;font-weight:bold;">✔ Aktif</span>' : '<span style="color:#d63638;font-weight:bold;">✖ Pasif</span>'; ?>
                    </p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <li><strong>Min. Sepet Tutarı:</strong> <?php echo esc_html( (string) self::MIN_SEPET_TUTARI ); ?> TL</li>
                        <li><strong>Min. Ürün Adedi:</strong> <?php echo esc_html( (string) self::MIN_URUN_ADEDI ); ?> adet</li>
                        <li><strong>Otomatik İndirim:</strong> %<?php echo esc_html( (string) ( self::VARSAYILAN_INDIRIM_ORAN * 100 ) ); ?></li>
                    </ul>
                </div>

                <!-- Seviye 3 Özeti (Dış API & Webhook) -->
                <div class="card" style="flex: 1; min-width: 320px; padding: 15px 25px; margin-top: 0; border-left: 4px solid #00a32a;">
                    <h2>🌐 Seviye 3: Dış REST API & Webhook</h2>
                    <p>
                        <strong>Canlı Depo Hava Durumu (<code>wp_remote_get</code>):</strong><br>
                        🌡️ Sıcaklık: <strong><?php echo esc_html( (string) $canli_hava_veri['sicaklik'] ); ?>°C</strong> | 
                        💨 Rüzgar: <strong><?php echo esc_html( (string) $canli_hava_veri['ruzgar'] ); ?> km/s</strong><br>
                        <small style="color: #666;">Kaynak: <?php echo esc_html( $canli_hava_veri['kaynak'] ); ?></small>
                    </p>

                    <form method="post" style="margin-top: 15px; display: flex; gap: 8px; flex-wrap: wrap;">
                        <?php wp_nonce_field( 'wc_asistan_admin_nonce' ); ?>
                        <button type="submit" name="wc_asistan_aksiyon" value="cache_temizle" class="button">
                            🔄 API Önbelleğini Temizle (GET)
                        </button>
                        <button type="submit" name="wc_asistan_aksiyon" value="crm_test_gonder" class="button button-primary">
                            🚀 CRM Webhook Test Et (POST)
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function site_alt_kisim_mesaji_yazdir(): void {
        $guncel_saat = wp_date( 'H:i' );
        $site_adi    = get_bloginfo( 'name' );

        $ham_mesaj = sprintf(
            '⚡ %s mağazasına hoş geldiniz! Sunucu saati: %s',
            $site_adi,
            $guncel_saat
        );

        $filtrelenmis_mesaj = apply_filters( 'wc_asistan_dinamik_footer_metni', $ham_mesaj, $guncel_saat );
        ?>
        <div id="wc-akilli-asistan-footer" style="background-color: #1d2327; color: #ffffff; text-align: center; padding: 12px 20px; font-family: sans-serif; font-size: 14px; border-top: 3px solid #2271b1;">
            <?php echo esc_html( (string) $filtrelenmis_mesaj ); ?>
        </div>
        <?php
    }

    public function admin_alt_bilgiyi_filtrele( string $mevcut_metin ): string {
        return '🚀 <strong>WC Akıllı Asistan (Seviye 3 - Full Stack)</strong> tarafından güçlendirildi | ' . $mevcut_metin;
    }

    public function footer_metnine_kampanya_ekle( string $mesaj, string $guncel_saat ): string {
        return sprintf(
            '%s | 🎁 Sepette %s TL üzeri veya en az %d ürüne anında %%%s Özel İndirim!',
            $mesaj,
            number_format_i18n( self::MIN_SEPET_TUTARI, 0 ),
            self::MIN_URUN_ADEDI,
            (string) ( self::VARSAYILAN_INDIRIM_ORAN * 100 )
        );
    }

    // =========================================================================
    // SEVİYE 2 METOTLARI (WooCommerce Sepet Müdahalesi)
    // =========================================================================

    public function sepete_ozel_indirim_uygula( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( $cart->is_empty() ) {
            return;
        }

        $sepet_ara_toplam  = (float) $cart->get_subtotal();
        $toplam_urun_adedi = (int) $cart->get_cart_contents_count();

        $tutar_sarti_saglandi = $sepet_ara_toplam >= self::MIN_SEPET_TUTARI;
        $adet_sarti_saglandi  = $toplam_urun_adedi >= self::MIN_URUN_ADEDI;

        if ( $tutar_sarti_saglandi || $adet_sarti_saglandi ) {
            $indirim_orani = (float) apply_filters(
                'wc_asistan_indirim_orani',
                self::VARSAYILAN_INDIRIM_ORAN,
                $cart
            );

            $indirim_tutari = $sepet_ara_toplam * $indirim_orani;

            $indirim_etiketi = $tutar_sarti_saglandi
                ? sprintf( '🎁 Özel Sepet Tutarı İndirimi (%%%s)', (string) ( $indirim_orani * 100 ) )
                : sprintf( '🎁 Çoklu Ürün (%d Adet) İndirimi (%%%s)', $toplam_urun_adedi, (string) ( $indirim_orani * 100 ) );

            $cart->add_fee( $indirim_etiketi, -$indirim_tutari, true );
        }
    }

    public function sepet_ust_bilgi_mesaji_goster(): void {
        if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
            return;
        }

        $cart              = WC()->cart;
        $sepet_ara_toplam  = (float) $cart->get_subtotal();
        $toplam_urun_adedi = (int) $cart->get_cart_contents_count();
        $yuzde_metni       = (string) ( self::VARSAYILAN_INDIRIM_ORAN * 100 );

        if ( $sepet_ara_toplam >= self::MIN_SEPET_TUTARI || $toplam_urun_adedi >= self::MIN_URUN_ADEDI ) {
            $kazanilan_indirim = $sepet_ara_toplam * self::VARSAYILAN_INDIRIM_ORAN;

            $tebrik_mesaji = sprintf(
                '🎉 <strong>Tebrikler!</strong> Akıllı Asistan kampanyasından <strong>%%%s indirim (%s)</strong> kazandınız! İndiriminiz sepet toplamına otomatik olarak yansıtıldı.',
                esc_html( $yuzde_metni ),
                wp_kses_post( wc_price( $kazanilan_indirim ) )
            );

            wc_print_notice( $tebrik_mesaji, 'success' );
            return;
        }

        $kalan_tutar = self::MIN_SEPET_TUTARI - $sepet_ara_toplam;
        $kalan_adet  = self::MIN_URUN_ADEDI - $toplam_urun_adedi;

        $tesvik_mesaji = sprintf(
            '💡 <strong>Fırsatı Kaçırmayın!</strong> Sepetinize <strong>%s</strong> değerinde daha ürün eklerseniz (veya sadece <strong>%d adet</strong> daha ürün eklerseniz) anında <strong>%%%s Özel İndirim</strong> kazanacaksınız!',
            wp_kses_post( wc_price( $kalan_tutar ) ),
            $kalan_adet,
            esc_html( $yuzde_metni )
        );

        wc_print_notice( $tesvik_mesaji, 'notice' );
    }

    public function sepet_urun_ismine_rozet_ekle( string $urun_adi, array $sepet_ogesi, string $sepet_anahtari ): string {
        $rozet_html = '<div style="margin-top: 4px; font-size: 12px; color: #0073aa;">✨ <em>Akıllı Asistan İndirim Kampanyasına Dahil</em></div>';

        return $urun_adi . $rozet_html;
    }

    // =========================================================================
    // SEVİYE 3 METOTLARI (Dış REST API `wp_remote_get` ve Webhook `wp_remote_post`)
    // =========================================================================

    /**
     * [HTTP GET & TRANSIENT CACHE] Dış REST API'den (Open-Meteo) canlı depo hava durumunu çeker.
     *
     * Kıdemli Geliştirici Prensibi:
     * Sayfa her yenilendiğinde dış sunucuya HTTP isteği atmak siteyi yavaşlatır.
     * Bu yüzden WordPress Transients API ile veriyi 30 dakika boyunca veritabanında önbellekliyoruz (Cache).
     *
     * @return array{sicaklik: float, ruzgar: float, durum_mesaji: string, kaynak: string}
     */
    public function dis_apiden_depo_hava_durumu_getir(): array {
        // 1. ADIM: Önce önbellekte (Transient) kayıtlı ve süresi dolmamış veri var mı kontrol et.
        $onbellekteki_veri = get_transient( self::TRANSIENT_HAVA_ANAHTARI );

        if ( false !== $onbellekteki_veri && is_array( $onbellekteki_veri ) ) {
            $onbellekteki_veri['kaynak'] = 'WordPress Transient Önbelleği (30 dk)';
            return $onbellekteki_veri;
        }

        // 2. ADIM: Önbellekte yoksa wp_remote_get() ile dış REST API'ye HTTP GET isteği at.
        $yanit = wp_remote_get(
            self::HAVA_DURUMU_API_URL,
            [
                'timeout'   => 5, // Sunucu 5 saniye içinde yanıt vermezse siteyi bekletme (Timeout koruması)
                'sslverify' => true,
                'headers'   => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        // 3. ADIM: Bağlantı hatası var mı (`is_wp_error`) veya HTTP durum kodu 200 (OK) dışında mı kontrol et.
        if ( is_wp_error( $yanit ) || 200 !== wp_remote_retrieve_response_code( $yanit ) ) {
            // Dış API çökmüşse bile müşteriye hata göstermemek için yedek (Fallback) veri döndür.
            return [
                'sicaklik'     => 20.0,
                'ruzgar'       => 10.0,
                'durum_mesaji' => '📦 Merkez depomuzda operasyon normal seyrediyor. Siparişiniz aynı gün kargoya hazır!',
                'kaynak'       => 'Yedek (Fallback) Sistem Verisi',
            ];
        }

        // 4. ADIM: Gelen JSON gövdesini (body) al ve PHP dizisine (associative array) dönüştür.
        $json_govde = wp_remote_retrieve_body( $yanit );
        $veri       = json_decode( $json_govde, true );

        $sicaklik = isset( $veri['current_weather']['temperature'] ) ? (float) $veri['current_weather']['temperature'] : 20.0;
        $ruzgar   = isset( $veri['current_weather']['windspeed'] ) ? (float) $veri['current_weather']['windspeed'] : 10.0;

        // Sıcaklık ve rüzgar verisine göre dinamik lojistik/teslimat mesajı üret.
        if ( $sicaklik < 10.0 || $ruzgar > 35.0 ) {
            $durum_mesaji = sprintf(
                '🌧️ Canlı Depo Hava Durumu (İstanbul: %s°C): Soğuk/rüzgarlı hava nedeniyle siparişiniz <strong>ücretsiz termal ve su geçirmez koruyucu ambalajla</strong> paketlenecektir!',
                (string) $sicaklik
            );
        } else {
            $durum_mesaji = sprintf(
                '🌤️ Canlı Depo Hava Durumu (İstanbul: %s°C): Hava koşulları harika! Siparişiniz <strong>bugün ekspres kargo</strong> ile yola çıkmaya hazır.',
                (string) $sicaklik
            );
        }

        $islenmis_veri = [
            'sicaklik'     => $sicaklik,
            'ruzgar'       => $ruzgar,
            'durum_mesaji' => $durum_mesaji,
            'kaynak'       => 'Canlı REST API (api.open-meteo.com)',
        ];

        // 5. ADIM: Çekilen taze veriyi 30 dakika (30 * MINUTE_IN_SECONDS) boyunca önbelleğe kaydet.
        set_transient( self::TRANSIENT_HAVA_ANAHTARI, $islenmis_veri, 30 * MINUTE_IN_SECONDS );

        return $islenmis_veri;
    }

    /**
     * [WC ACTION CALLBACK] Sepet ve Ödeme (Checkout) sayfalarında canlı API verisini şık bir kutuda gösterir.
     * Kancalar: 'woocommerce_before_checkout_form' ve 'woocommerce_proceed_to_checkout'
     *
     * @return void
     */
    public function odeme_sayfasinda_api_bilgisi_goster(): void {
        $api_verisi = $this->dis_apiden_depo_hava_durumu_getir();

        /**
         * Mesajı ekrana basmadan önce bir Filter Hook'tan geçiriyoruz ki
         * başka eklentiler veya temalar bu API mesajını özelleştirebilsin.
         */
        $mesaj_html = (string) apply_filters(
            'wc_asistan_api_lojistik_mesaji',
            $api_verisi['durum_mesaji'],
            $api_verisi
        );
        ?>
        <div class="wc-asistan-api-kutu" style="background: #f0f6fc; border: 1px solid #c5d9ed; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 16px; border-radius: 4px; font-size: 14px; color: #1d2327;">
            <?php echo wp_kses_post( $mesaj_html ); ?>
        </div>
        <?php
    }

    /**
     * [WC ACTION CALLBACK - HTTP POST WEBHOOK]
     * Sipariş tamamlandığında ('woocommerce_thankyou' kancası) sipariş verilerini dış CRM sunucusuna JSON olarak POST eder.
     *
     * @param int $order_id WooCommerce tarafından otomatik gönderilen Sipariş ID'si.
     * @return void
     */
    public function siparisi_dis_crm_webhookuna_gonder( int $order_id ): void {
        if ( $order_id <= 0 ) {
            return;
        }

        // Sipariş nesnesini (WC_Order) alıyoruz.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // TEKRAR GÖNDERİM KORUMASI (Idempotency):
        // Müşteri "Teşekkürler" sayfasını F5 ile yenilerse aynı siparişi CRM'e tekrar tekrar göndermeyelim!
        if ( 'evet' === $order->get_meta( '_wc_asistan_crm_webhook_gonderildi', true ) ) {
            return;
        }

        // Dış CRM API'sine gönderilecek JSON veri paketini (Payload) hazırlıyoruz.
        $payload = [
            'olay'            => 'yeni_siparis_olustu',
            'siparis_id'      => $order->get_id(),
            'siparis_toplami' => (float) $order->get_total(),
            'para_birimi'     => $order->get_currency(),
            'musteri_eposta'  => $order->get_billing_email(),
            'musteri_ad_soyad'=> $order->get_formatted_billing_full_name(),
            'tarih'           => wp_date( 'c' ),
        ];

        // wp_remote_post() ile dış CRM Webhook adresine HTTP POST isteği atıyoruz.
        $yanit = wp_remote_post(
            self::CRM_WEBHOOK_API_URL,
            [
                'method'  => 'POST',
                'timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'X-Source'     => 'WC-Akilli-Asistan-Plugin',
                ],
                'body'    => wp_json_encode( $payload ),
            ]
        );

        // Yanıt başarılıysa (HTTP 200 veya 201 Created) siparişe not düş ve meta veriyi işaretle.
        if ( ! is_wp_error( $yanit ) && in_array( wp_remote_retrieve_response_code( $yanit ), [ 200, 201 ], true ) ) {
            $order->update_meta_data( '_wc_asistan_crm_webhook_gonderildi', 'evet' );
            $order->add_order_note( '✅ Sipariş bilgileri WC Akıllı Asistan tarafından dış CRM Webhook servisine başarıyla iletildi.' );
            $order->save();
        }
    }

    /**
     * [ADMIN TEST METODU] Admin panelindeki "CRM Webhook Test Et" butonuna basıldığında örnek bir POST isteği atar.
     *
     * @return array{basarili: bool, mesaj: string}
     */
    private function ornek_crm_webhook_testi_yap(): array {
        $test_verisi = [
            'olay'       => 'admin_baglanti_testi',
            'site_url'   => home_url(),
            'zaman'      => wp_date( 'Y-m-d H:i:s' ),
        ];

        $yanit = wp_remote_post(
            self::CRM_WEBHOOK_API_URL,
            [
                'timeout' => 5,
                'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
                'body'    => wp_json_encode( $test_verisi ),
            ]
        );

        if ( is_wp_error( $yanit ) ) {
            return [
                'basarili' => false,
                'mesaj'    => 'Hata oluştu: ' . $yanit->get_error_message(),
            ];
        }

        $durum_kodu = (int) wp_remote_retrieve_response_code( $yanit );
        $govde      = wp_remote_retrieve_body( $yanit );
        $cozulen    = json_decode( $govde, true );
        $uzak_id    = isset( $cozulen['id'] ) ? (string) $cozulen['id'] : 'Bilinmiyor';

        return [
            'basarili' => in_array( $durum_kodu, [ 200, 201 ], true ),
            'mesaj'    => sprintf(
                'HTTP %d Başarılı! Dış CRM sunucusu (%s) veriyi kabul etti ve Kayıt ID #%s oluşturdu.',
                $durum_kodu,
                self::CRM_WEBHOOK_API_URL,
                $uzak_id
            ),
        ];
    }
}

/**
 * Eklentiyi 'plugins_loaded' aşamasında başlatıyoruz.
 */
add_action( 'plugins_loaded', static function (): void {
    new WC_Akilli_Asistan();
} );
