<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * CodeIgniter Email Queue
 *
 * A CodeIgniter library to queue e-mails.
 *
 * @package     CodeIgniter
 * @category    Libraries
 * @author      Thaynã Bruno Moretti
 * @link	http://www.meau.com.br/
 * @license	http://www.opensource.org/licenses/mit-license.html
 */
class MY_Email extends CI_Email
{
    // DB table
	private $table_email_queue = 'email_queue';

    // Main controller
	private $main_controller = 'sys/queue_email/send_pending_emails';

    // PHP Nohup command line
	private $phpcli = 'nohup php';
	private $expiration = NULL;

    // Status (pending, sending, sent, failed)
    private $status;
	
	/**
	 * Constructor
	 */
	public function __construct($config = array())
	{
        parent::__construct($config);

        log_message('debug', 'Email Queue Class Initialized');

		$this->expiration = 60*5;
        $this->CI = & get_instance();
	}

	public function set_status($status)
	{
		$this->status = $status;
		return $this;
	}

	/**
	 * Get
	 *
	 * Get queue emails.
	 * @return 	mixed
	 */
	public function get($limit = NULL, $offset = NULL)
	{
		if ($this->status != FALSE)
			$this->CI->db->where('q.status', $this->status);
		
		$query = $this->CI->db->get("{$this->table_email_queue} q", $limit, $offset);

		return $query->result();
	}

	/**
	 * Save
	 *
	 * Add queue email to database.
	 * @return 	mixed
	 */
	public function send($skip_job = FALSE)
	{
        if ( $skip_job === TRUE ) {
            return parent::send();
        }

		$date = date("Y-m-d H:i:s");

		$dbdata = array(
            'to' => implode(", ", $this->_recipients),
			'message' => $this->_body,
            'headers' => serialize($this->_headers),
			'status' => 'pending',
			'date' => $date
		);

		return $this->CI->db->insert($this->table_email_queue, $dbdata);
	}
	
	/**
	 * Start process
	 *
	 * Start php process to send emails
	 * @return 	mixed
	 */
	public function start_process()
	{
		$filename = FCPATH . 'index.php';		
		$exec = shell_exec("{$this->phpcli} {$filename} {$this->main_controller} > /dev/null &");

		return $exec;
	}
	
	/**
	 * Send queue
	 *
	 * Send queue emails.
	 * @return 	void
	 */
	public function send_queue()
	{
        $this->set_status('pending');
		$emails = $this->get();
		
		$this->CI->db->where('status', 'pending');
		$this->CI->db->set('status', 'sending');
		$this->CI->db->set('date', date("Y-m-d H:i:s"));
		$this->CI->db->update($this->table_email_queue);
			
		foreach ($emails as $email)
		{
            $recipients = explode(", ", $email->to);
            $this->_headers = unserialize($email->headers);
            
            $this->to($recipients);
			$this->message($email->message);

            if ($this->send(TRUE)) {
                $status = 'sent';
            } else {
                $status = 'failed';
            }

			$this->CI->db->where('id', $email->id);
			
			$this->CI->db->set('status', $status);
			$this->CI->db->set('date', date("Y-m-d H:i:s"));
			$this->CI->db->update($this->table_email_queue);
		}
	}
	
	/**
	 * Retry failed emails
	 *
	 * Resend failed or expired emails
	 * @return void 
	 */
	public function retry_queue() 
	{
		$expire = (time() - $this->expiration);
		$date_expire = date("Y-m-d H:i:s", $expire);

		$this->CI->db->set('status', 'pending');
		$this->CI->db->where("(date < '{$date_expire}' AND status = 'sending')");
		$this->CI->db->or_where("status = 'failed'");
		
		$this->CI->db->update($this->table_email_queue);
		
		$this->status('pending');
		$this->send();
		
		log_message('debug', 'Email queue retrying...');
	}
}
