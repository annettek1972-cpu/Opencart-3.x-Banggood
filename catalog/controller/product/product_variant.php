<?php
/**
 * Compatibility endpoint for themes that call product/product_variant.
 *
 * Returns variant stock/price for a selected option combination from `product_variant`.
 *
 * Inputs (GET or POST):
 * - product_id (int, required)
 * - option_key (string, optional) e.g. "287620,287837" or "287620|287837"
 *   OR pov[] (array of ints) e.g. pov[]=287620&pov[]=287837
 *
 * Output JSON:
 * - found (bool)
 * - quantity (int|null)
 * - price (float|null)
 * - variant_id (int|null)
 * - option_key (string|null)
 * - option_text (string|null)
 */
class ControllerProductProductVariant extends Controller {
	private function stockTokenToText($token) {
		$token = trim((string)$token);
		if ($token === '') return '';

		$upper = strtoupper($token);

		// Common Banggood tokens the user referenced
		if (strpos($upper, 'LC_STOCK_MSG_EXPECT') === 0) {
			// Many sites treat EXPECT as sold out/backorder messaging; per request show Sold Out
			if (preg_match('/^LC_STOCK_MSG_EXPECT_(\d+)$/i', $upper, $m)) {
				$d = (int)$m[1];
				if ($d > 0) return 'Stock Expected In ' . $d . ' ' . ($d === 1 ? 'day' : 'days');
			}
			return 'Sold Out';
		}

		if (strpos($upper, 'LC_STOCK_MSG_SOLD') === 0 || strpos($upper, 'SOLD_OUT') !== false || strpos($upper, 'OUT_OF_STOCK') !== false) {
			return 'Sold Out';
		}

		if (preg_match('/^LC_STOCK_MSG_(\d+)_DAYS$/i', $upper, $m)) {
			$days = (int)$m[1];
			if ($days > 0) return 'ships in ' . ($days * 24) . ' hours';
		}
		if (preg_match('/^LC_STOCK_MSG_(\d+)_HOURS$/i', $upper, $m)) {
			$hours = (int)$m[1];
			if ($hours > 0) return 'ships in ' . $hours . ' hours';
		}

		// If API returns a full English phrase, pass through unchanged
		return $token;
	}

	public function index() {
		$this->response->addHeader('Content-Type: application/json');

		$json = array(
			'found' => false,
			'quantity' => null,
			'price' => null,
			'variant_id' => null,
			'option_key' => null,
			'option_text' => null,
			'stock_status_token' => null,
			'stock_status_text' => null,
			'pov' => array()
		);

		try {
			$product_id = 0;
			if (isset($this->request->get['product_id'])) $product_id = (int)$this->request->get['product_id'];
			elseif (isset($this->request->post['product_id'])) $product_id = (int)$this->request->post['product_id'];

			if ($product_id <= 0) {
				$this->response->setOutput(json_encode(array('error' => 'product_id is required')));
				return;
			}

			$raw_key = '';
			if (isset($this->request->get['option_key'])) $raw_key = (string)$this->request->get['option_key'];
			elseif (isset($this->request->post['option_key'])) $raw_key = (string)$this->request->post['option_key'];

			$pov_ids = array();
			if (isset($this->request->get['pov']) && is_array($this->request->get['pov'])) {
				$pov_ids = $this->request->get['pov'];
			} elseif (isset($this->request->post['pov']) && is_array($this->request->post['pov'])) {
				$pov_ids = $this->request->post['pov'];
			} elseif ($raw_key !== '') {
				$pov_ids = preg_split('/[,\|\s;]+/', trim($raw_key));
			}

			$pov_ids = array_values(array_unique(array_map('intval', array_filter(array_map('trim', (array)$pov_ids), function($v){ return $v !== ''; }))));

			// Auto-select: if no option selection is provided, return the first available variant.
			$auto = false;
			if (isset($this->request->get['auto_select'])) $auto = (bool)$this->request->get['auto_select'];
			elseif (isset($this->request->post['auto_select'])) $auto = (bool)$this->request->post['auto_select'];

			if (empty($pov_ids) && $auto) {
				$tbl = DB_PREFIX . 'product_variant';
				$q0 = $this->db->query(
					"SELECT variant_id, option_key, option_text, quantity, price, stock_status_token
					 FROM `" . $tbl . "`
					 WHERE product_id = " . (int)$product_id . " AND quantity > 0
					 ORDER BY variant_id ASC
					 LIMIT 1"
				);
				if (!$q0 || !$q0->num_rows) {
					$q0 = $this->db->query(
						"SELECT variant_id, option_key, option_text, quantity, price, stock_status_token
						 FROM `" . $tbl . "`
						 WHERE product_id = " . (int)$product_id . "
						 ORDER BY variant_id ASC
						 LIMIT 1"
					);
				}
				if ($q0 && $q0->num_rows) {
					$row = $q0->row;
					$json['found'] = true;
					$json['variant_id'] = isset($row['variant_id']) ? (int)$row['variant_id'] : null;
					$json['quantity'] = isset($row['quantity']) ? (int)$row['quantity'] : null;
					$json['price'] = isset($row['price']) ? (float)$row['price'] : null;
					$json['option_key'] = isset($row['option_key']) ? (string)$row['option_key'] : null;
					$json['option_text'] = isset($row['option_text']) ? (string)$row['option_text'] : null;
					$json['stock_status_token'] = isset($row['stock_status_token']) ? (string)$row['stock_status_token'] : null;
					$json['stock_status_text'] = $this->stockTokenToText($json['stock_status_token']);
					// Provide parsed pov ids for frontend auto-selection.
					$key = isset($row['option_key']) ? (string)$row['option_key'] : '';
					$ids = preg_split('/[,\|\s;]+/', trim($key));
					$ids = array_values(array_unique(array_map('intval', array_filter(array_map('trim', (array)$ids), function($v){ return $v !== ''; }))));
					$json['pov'] = $ids;
				}
				$this->response->setOutput(json_encode($json));
				return;
			}

			if (empty($pov_ids)) {
				$this->response->setOutput(json_encode(array('error' => 'option_key or pov[] is required')));
				return;
			}

			$norm = implode(',', $pov_ids);
			$sorted = $pov_ids; sort($sorted, SORT_NUMERIC);
			$sorted_norm = implode(',', $sorted);
			$pipe_norm = implode('|', $pov_ids);

			$tbl = DB_PREFIX . 'product_variant';

			$q = $this->db->query(
				"SELECT variant_id, option_key, option_text, quantity, price, stock_status_token
				 FROM `" . $tbl . "`
				 WHERE product_id = " . (int)$product_id . "
				   AND option_key IN (
					 '" . $this->db->escape($raw_key) . "',
					 '" . $this->db->escape($norm) . "',
					 '" . $this->db->escape($sorted_norm) . "',
					 '" . $this->db->escape($pipe_norm) . "'
				   )
				 ORDER BY (stock_status_token IS NOT NULL AND stock_status_token <> '') DESC, variant_id DESC
				 LIMIT 1"
			);

			if (!$q || !$q->num_rows) {
				$cand = $this->db->query(
					"SELECT variant_id, option_key, option_text, quantity, price, stock_status_token
					 FROM `" . $tbl . "`
					 WHERE product_id = " . (int)$product_id
				);
				if ($cand && $cand->num_rows) {
					$best = null;
					foreach ($cand->rows as $r) {
						$ok = isset($r['option_key']) ? (string)$r['option_key'] : '';
						if ($ok === '') continue;
						$p = preg_split('/[,\|\s;]+/', trim($ok));
						$p = array_values(array_unique(array_map('intval', array_filter(array_map('trim', $p), function($v){ return $v !== ''; }))));
						sort($p, SORT_NUMERIC);
						if ($p === $sorted) {
							// Prefer a row that has a stock_status_token
							if ($best === null) {
								$best = $r;
							} else {
								$bestHas = (!empty($best['stock_status_token']));
								$rHas = (!empty($r['stock_status_token']));
								if ($rHas && !$bestHas) $best = $r;
								elseif ($rHas === $bestHas) {
									// Tie-breaker: prefer higher variant_id
									$bid = isset($best['variant_id']) ? (int)$best['variant_id'] : 0;
									$rid = isset($r['variant_id']) ? (int)$r['variant_id'] : 0;
									if ($rid > $bid) $best = $r;
								}
							}
						}
					}
					if ($best !== null) {
						$q = (object)array('num_rows' => 1, 'row' => $best);
					}
				}
			}

			if ($q && isset($q->num_rows) && $q->num_rows) {
				$row = $q->row;
				$json['found'] = true;
				$json['variant_id'] = isset($row['variant_id']) ? (int)$row['variant_id'] : null;
				$json['quantity'] = isset($row['quantity']) ? (int)$row['quantity'] : null;
				$json['price'] = isset($row['price']) ? (float)$row['price'] : null;
				$json['option_key'] = isset($row['option_key']) ? (string)$row['option_key'] : $norm;
				$json['option_text'] = isset($row['option_text']) ? (string)$row['option_text'] : null;
				$json['stock_status_token'] = isset($row['stock_status_token']) ? (string)$row['stock_status_token'] : null;
				$json['stock_status_text'] = $this->stockTokenToText($json['stock_status_token']);
				$json['pov'] = $pov_ids;
			}

			$this->response->setOutput(json_encode($json));
		} catch (\Throwable $e) {
			$this->response->setOutput(json_encode(array('error' => 'product_variant failed: ' . $e->getMessage())));
		}
	}
}

