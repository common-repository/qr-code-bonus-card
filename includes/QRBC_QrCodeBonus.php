<?php

class QRBC_QrCodeBonus
{
    private $user_unique;
    private $user;
    private $bonuses;
    private $last_bonus;
    private $last_win;
    private $win_count;

    public function __construct($user_unique = null)
    {
        $this->user_unique = sanitize_text_field($user_unique);
        $this->win_count = (int)get_option('qr_bonus_win_count');
        $this->getUser();
    }

    public function getUser()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "qr_bonus_users";
        $user = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_unique = %s LIMIT 1", $this->user_unique));
        if (count($user) == 0) {
            $user_unique_id = uniqid('qr-') . '-' . time();
            $date = current_time('mysql');
            $wpdb->insert($table_name, ['user_unique' => $user_unique_id, 'device' => sanitize_text_field(@$_SERVER['HTTP_USER_AGENT']), 'created_at' => $date]);
            setcookie('bonus_user', $user_unique_id, time() + (86400 * 30 * 36), "/");
            $user = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_unique = %s LIMIT 1", $user_unique_id));
        }
        $this->user = $user[0];
        return $user[0];
    }

    public function getActiveBonuses($order = 'DESC', $limit = null)
    {
        global $wpdb;

        if ($order != 'DESC') {
            $order = 'ASC';
        }
        if ($limit) {
            $limit = $wpdb->prepare('LIMIT %d', $limit);
        }

        $table_name = $wpdb->prefix . "qr_bonuses";
        $bonuses = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bonus_user_id = %d AND status = 1 ORDER BY id {$order} {$limit}", $this->user->id));
        $this->bonuses = $bonuses;
        return $bonuses;
    }

    public function getLastBonus()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "qr_bonuses";
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bonus_user_id = %d ORDER BY id DESC LIMIT 1", $this->user->id));
        if ($results and @$results[0]) {
            $this->last_bonus = $results[0];
            return $results[0];
        } else {
            $this->last_bonus = null;
            return null;
        }
    }

    public function getLastBonusDate()
    {
        $this->getLastBonus();
        if ($this->last_bonus) {
            return date(get_option('qr_bonus_date_format'), strtotime($this->last_bonus->created_at));
        }
        return '-';
    }

    public function getWinCount()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "qr_bonus_wins";
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bonus_user_id = %d ", $this->user->id));
        return $results ? count($results) : 0;
    }

    public function getLastWin()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "qr_bonus_wins";
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bonus_user_id = %d ORDER BY id DESC LIMIT 1", $this->user->id));
        if ($results and @$results[0]) {
            $this->last_win = $results[0];
            return $results[0];
        } else {
            $this->last_win = null;
            return null;
        }
    }

    public function getLastWinDate()
    {
        $this->getLastWin();
        if ($this->last_win) {
            return date(get_option('qr_bonus_date_format'), strtotime($this->last_win->created_at));
        }
        return '-';
    }

    public function createBonus($checksum)
    {
        $checksum = sanitize_text_field($checksum);
        $this->getLastBonus();
        global $wpdb;
        $table_name = $wpdb->prefix . "qr_bonuses";
        if ($this->last_bonus) {
            $checksum_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bonus_user_id = %d AND checksum = %s LIMIT 1", $this->user->id, $checksum));
            if (@$checksum_results[0]) {
                return ['status' => false, 'message' => __('It is not possible to re-register a duplicate bonus.', 'qrbc'), 'is_winner' => false];
            }
        }

        $date = current_time('mysql');
        $count = 1;
        if (str_contains($checksum, '--')) {
            $checksum_arr = explode('--', $checksum);
            $count = @$checksum_arr[1];
            $count = $count ? (int)$count : 1;
        }
        $active_bonus_count_plus_count = count($this->getActiveBonuses()) + $count;
        if ($this->win_count < $active_bonus_count_plus_count) {
            $count = $count - 1;
        }
        $created = false;
        for ($i = 1; $i <= $count; $i++) {
            $wpdb->insert($table_name, ['bonus_user_id' => $this->user->id, 'checksum' => $checksum, 'status' => 1, 'created_at' => $date]);
            $created = true;
        }
        $is_winner = $this->createBonusWin($active_bonus_count_plus_count, $created);
        return ['status' => true, 'message' => __('Your bonus has been successfully registered.', 'qrbc') , 'is_winner' => $is_winner];
    }

    public function createBonusWin($count_bonuses, $created = true)
    {
        if ($count_bonuses > $this->win_count or ($count_bonuses == $this->win_count and !$created)) {
            $this->getActiveBonuses('ASC', $this->win_count);
            if (is_array($this->bonuses)) {
                $ids = array_column($this->bonuses, 'id');
                $ids_string = implode(',', $ids);

                global $wpdb;
                $bonuses_table_name = $wpdb->prefix . "qr_bonuses";
                foreach ($this->bonuses as $bonus) {
                    $wpdb->update($bonuses_table_name, ['status' => 0], ['id' => $bonus->id]);
                }
                $table_name = $wpdb->prefix . "qr_bonus_wins";
                $date = current_time('mysql');
                $wpdb->insert($table_name, ['bonus_user_id' => $this->user->id, 'bonus_ids' => $ids_string, 'created_at' => $date]);
                return true;
            }
        }
        return false;
    }
}