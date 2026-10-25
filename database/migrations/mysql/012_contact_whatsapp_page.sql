UPDATE content_pages
SET title = 'Contact',
    body_html = '<p>Scrie-ne despre produsul, mărimea, comanda sau cadoul pentru care ai nevoie de ajutor. Formularul pregătește mesajul și deschide conversația WhatsApp cu La Măriuca Boutique.</p>',
    status = 'published',
    legal_review_required = 0,
    seo_title = 'Contact La Măriuca Boutique',
    meta_description = 'Contactează La Măriuca Boutique direct pe WhatsApp pentru ajutor cu produse, mărimi, comenzi, botezuri sau cadouri.',
    indexable = 1,
    updated_at = CURRENT_TIMESTAMP
WHERE slug = 'contact';
