-- ============================================================================
--  BEBBA — ASSAINISSEMENT DE LA BASE  (BLOC 8.0)
--  Base cible : bebba_test
--  A executer sur une sauvegarde fraiche. Transactionnel.
-- ============================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- ETAPE 0 : extension du vocabulaire de tracabilite
-- (ajout de valeurs en fin d'ENUM : non destructif, reversible)
-- ---------------------------------------------------------------------------
ALTER TABLE bebba_migration_map
  MODIFY COLUMN status ENUM('pending','migrated','failed','quarantined','skipped','purged')
  NOT NULL DEFAULT 'pending';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- ETAPE 1 : purge des commandes de test
--   Conservees : Sami Ben Ali, Sami Khelifi, Meriem Mansouri, Nidhal Gharbi
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE tmp_ko (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_ko
  SELECT id FROM bebba_orders
  WHERE customer_name IS NULL
     OR customer_name NOT IN ('Sami Ben Ali','Sami Khelifi','Meriem Mansouri','Nidhal Gharbi');

DELETE s FROM bebba_order_item_supplements s
  JOIN bebba_order_items i ON i.id = s.order_item_id
  JOIN tmp_ko k             ON k.id = i.order_id;

DELETE p FROM bebba_order_item_prep p
  JOIN bebba_order_items i ON i.id = p.order_item_id
  JOIN tmp_ko k             ON k.id = i.order_id;

DELETE i FROM bebba_order_items i JOIN tmp_ko k ON k.id = i.order_id;
DELETE h FROM bebba_order_status_history h JOIN tmp_ko k ON k.id = h.order_id;
DELETE m FROM bebba_stock_movements m JOIN tmp_ko k ON k.id = m.order_id;
DELETE x FROM bebba_order_idempotency x JOIN tmp_ko k ON k.id = x.order_id;
DELETE o FROM bebba_orders o JOIN tmp_ko k ON k.id = o.id;

-- les mouvements de stock restants (saisies manuelles) : on repart d'un stock neuf
DELETE FROM bebba_stock_movements;

-- ---------------------------------------------------------------------------
-- ETAPE 2 : tracabilite honnete
-- ---------------------------------------------------------------------------
-- 2a. les lignes correspondant aux commandes purgees
UPDATE bebba_migration_map
SET status = 'purged',
    error_message = 'Commande de test purgee le 2026-09-22 (assainissement BLOC 8.0) : client fictif.'
WHERE legacy_type = 'order'
  AND target_id IN (SELECT id FROM tmp_ko);

-- 2b. les 2 lignes qui declaraient "migre" sans qu'aucune ligne n'existe
UPDATE bebba_migration_map
SET status = 'failed',
    error_message = 'Aucune ligne en base : sous-total et total absents dans la source, insertion annulee lors de la migration.'
WHERE legacy_id IN ('ord-1788252056636','ord-1788251296856');

-- 2c. les 2 doublons ecartes volontairement
INSERT INTO bebba_migration_map
  (legacy_type, legacy_id, target_table, batch_id, status, error_message)
VALUES
  ('product','prod-1788252928280','bebba_products','assainissement-2026-09-22','skipped',
   'Doublon de prod-1788252897607 (meme produit, cree 31 s plus tard). Non importe volontairement.'),
  ('supplement','sup-1788252928282','bebba_supplements','assainissement-2026-09-22','skipped',
   'Doublon de sup-1788252897609 (meme supplement, cree 31 s plus tard). Non importe volontairement.');

-- ---------------------------------------------------------------------------
-- ETAPE 2bis : references supplement non resolvables
--   La source contient des identifiants de FOURNISSEUR (sup-1, sup-3) dans le
--   champ des supplements autorises du produit Wrap Fitness Poulet Avocat.
--   Ces references ne peuvent pas etre resolues : on retire les 2 lignes.
-- ---------------------------------------------------------------------------
DELETE FROM bebba_product_supplements WHERE supplement_id IS NULL;

-- ---------------------------------------------------------------------------
-- ETAPE 3 : les 2 livreurs manquants + remise a zero des statistiques
-- ---------------------------------------------------------------------------
INSERT INTO bebba_drivers
  (id, legacy_id, name, phone, vehicle, active, total_deliveries, rating,
   legacy_user_id, user_id, created_at, updated_at)
VALUES
  (2,'drv-2','Amine Trabelsi','+216 55 987 654','Moto Peugeot Tweet',1,0,NULL,NULL,NULL,NOW(),NOW()),
  (3,'drv-3','Karim Bouazizi','+216 22 456 789','Vélo Électrique Cargo',1,0,NULL,NULL,NULL,NOW(),NOW());

-- aucune course reelle n'a encore ete effectuee : statistiques remises a zero
UPDATE bebba_drivers SET total_deliveries = 0, rating = NULL;

-- ---------------------------------------------------------------------------
-- ETAPE 4 : reparation des 5 commandes conservees
-- ---------------------------------------------------------------------------
-- 4a. articles sans prix : on reprend le prix catalogue du produit
UPDATE bebba_order_items i
  JOIN bebba_products p ON p.id = i.product_id
SET i.unit_price       = p.base_price,
    i.item_total_price = ROUND(p.base_price * i.quantity, 2)
WHERE i.unit_price IS NULL OR i.item_total_price IS NULL;

-- 4b. sous-total et total recalcules depuis les articles
UPDATE bebba_orders o
SET o.subtotal     = (SELECT ROUND(COALESCE(SUM(i.item_total_price),0),2)
                      FROM bebba_order_items i WHERE i.order_id = o.id),
    o.total_amount = (SELECT ROUND(COALESCE(SUM(i.item_total_price),0),2)
                      FROM bebba_order_items i WHERE i.order_id = o.id)
                     + COALESCE(o.delivery_fee, 0)
WHERE o.total_amount IS NULL OR o.subtotal IS NULL;

-- 4c. commandes passees : le stock a deja ete consomme
UPDATE bebba_orders SET stock_consumed = 1 WHERE stock_consumed IS NULL OR stock_consumed = 0;

-- ---------------------------------------------------------------------------
-- ETAPE 5 : renumeroation des numeros de commande
-- ---------------------------------------------------------------------------
UPDATE bebba_orders SET order_number = 'BEBBA-1001' WHERE legacy_id = 'ord-1047';
UPDATE bebba_orders SET order_number = 'BEBBA-1002' WHERE legacy_id = 'ord-1048';
UPDATE bebba_orders SET order_number = 'BEBBA-1003' WHERE legacy_id = 'ord-1049';
UPDATE bebba_orders SET order_number = 'BEBBA-1004' WHERE legacy_id = 'ord-1788252034197';
UPDATE bebba_orders SET order_number = 'BEBBA-1005' WHERE legacy_id = 'ord-1788252052678';
UPDATE bebba_counters SET current_value = 1006 WHERE counter_name = 'order_sequence';

-- ---------------------------------------------------------------------------
-- ETAPE 6 : nettoyage du catalogue (desactivation, jamais de suppression)
-- ---------------------------------------------------------------------------
UPDATE bebba_products SET active = 0, is_available = 0
  WHERE legacy_id IN ('prod-1788693673602','prod-test-indisponible');

UPDATE bebba_ingredients SET active = 0
  WHERE legacy_id IN ('ing-1788825545393','test-ing-kitchen-1788896746450');

UPDATE bebba_ingredients SET unit = 'g'
  WHERE legacy_id = 'test-ing-kitchen-1788896746450' AND (unit IS NULL OR unit = '');

UPDATE bebba_categories SET active = 0 WHERE legacy_id = 'cat-1788252928275';

-- ---------------------------------------------------------------------------
-- ETAPE 7 : grille de stock (3 jours de service, seuil d'alerte 1 jour)
-- ---------------------------------------------------------------------------
UPDATE bebba_ingredients SET stock_quantity=20000, min_threshold=6000 WHERE legacy_id='ing-poulet';
UPDATE bebba_ingredients SET stock_quantity=12000, min_threshold=4000 WHERE legacy_id='ing-boeuf';
UPDATE bebba_ingredients SET stock_quantity=12000, min_threshold=3500 WHERE legacy_id='ing-dinde';
UPDATE bebba_ingredients SET stock_quantity= 9000, min_threshold=2500 WHERE legacy_id='ing-saumon';
UPDATE bebba_ingredients SET stock_quantity= 6000, min_threshold=1500 WHERE legacy_id='ing-halloumi';
UPDATE bebba_ingredients SET stock_quantity=   90, min_threshold=  24 WHERE legacy_id='ing-oeuf';
UPDATE bebba_ingredients SET stock_quantity= 8000, min_threshold=2000 WHERE legacy_id='ing-avocat';
UPDATE bebba_ingredients SET stock_quantity=18000, min_threshold=5000 WHERE legacy_id='ing-legumes';
UPDATE bebba_ingredients SET stock_quantity=14000, min_threshold=4000 WHERE legacy_id='ing-patate-douce';
UPDATE bebba_ingredients SET stock_quantity=20000, min_threshold=5000 WHERE legacy_id='ing-riz';
UPDATE bebba_ingredients SET stock_quantity=12000, min_threshold=3500 WHERE legacy_id='ing-quinoa';
UPDATE bebba_ingredients SET stock_quantity=14000, min_threshold=4000 WHERE legacy_id='ing-fruits-frais';
UPDATE bebba_ingredients SET stock_quantity=10000, min_threshold=2500 WHERE legacy_id='ing-betterave';
UPDATE bebba_ingredients SET stock_quantity=10000, min_threshold=3000 WHERE legacy_id='ing-eau-infusee';
UPDATE bebba_ingredients SET stock_quantity= 5000, min_threshold=1200 WHERE legacy_id='ing-sauce-healthy';
UPDATE bebba_ingredients SET stock_quantity= 4000, min_threshold=1000 WHERE legacy_id='ing-sauce-miel-moutarde';
UPDATE bebba_ingredients SET stock_quantity=   60, min_threshold=  15 WHERE legacy_id='ing-repas-programme';

COMMIT;
