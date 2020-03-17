<?php

/**
 * Login class - login procedure
 *
 * @author  Marcel Folaron <marcel.folaron@gmail.com>
 * @version 1.0
 * @license GNU/GPL, see license.txt
 */
namespace leantime\core {

    use PDO;
    use leantime\domain\repositories;

    class login
    {

        /**
         * @access private
         * @var    integer user id from DB
         */
        private $userId = null;

        /**
         * @access private
         * @var    integer user id from DB
         */
        private $clientId = null;

        /**
         * @access private
         * @var    string username from db
         */
        private $username = null;

        /**
         * @access private
         * @var    string username from db
         */
        private $name = '';

        /**
         * @access private
         * @var    string
         */
        private $password = null;

        /**
         * @access private
         * @var    string username (emailaddress)
         */
        private $user = null;

        /**
         * @access private
         * @var    string username (emailaddress)
         */
        private $mail = null;

        /**
         * @access private
         * @var    string
         */
        private $session = null;

        /**
         * @access private
         * @var    object - db connection
         */
        private $db = null;

        /**
         * @access public
         * @var    string userrole (admin, client, employee)
         */
        public $role = '';

        public $settings = '';

        /**
         * @access public
         * @var    integer time for cookie
         */
        public $cookieTime = 7200;

        /**
         * @access public
         * @var    string
         */
        public $error = "";

        /**
         * @access public
         * @var    string
         */
        public $success = "";

        /**
         * @access public
         * @var    string
         */
        public $resetInProgress = false;

        /**
         * @access public
         * @var    object
         */
        public $hasher;


        public static $userRoles = array(
            10   => 'client',
            20   => 'developer',
            30   => 'clientManager',
            40   => 'manager',
            50   => 'admin'
        );

        /*
         * Clientmanager roles
         * ClientManagers can only add and remove a set of rules
         */
        public static $clientManagerRoles = array(
            10   => 'client',
            20   => 'developer',
            30   => 'clientManager'
        );

        private static $instance;

        /**
         * __construct - getInstance of session and get sessionId and refers to login if post is set
         *
         * @param  $sessionid
         * @return boolean
         */
        private function __construct($sessionid)
        {

            $this->db = db::getInstance();

            $config = new config();
            $this->cookieTime = $config->sessionExpiration;

            $this->session = $sessionid;

            if (isset($_POST['login'])===true && isset($_POST['username'])===true && isset($_POST['password'])===true) {

                $this->username = filter_var($_POST['username'], FILTER_SANITIZE_EMAIL);

                $this->password = ($_POST['password']);

                $redirectUrl = filter_var($_POST['redirectUrl'], FILTER_SANITIZE_URL);

                //If login successful redirect to the correct url to avoid post on reload
                if($this->login() === true){

                    $this->checkSessions();
                    header("Location:".$redirectUrl);
                    exit();
                }

            }

            //Reset password
            if(isset($_POST["resetPassword"])) {

                if(isset($_POST['username']) === true) {
                    //Look for email address and send email
                    $userFromDB = $this->getUser($_POST["username"]);

                    if($userFromDB !== false && count($userFromDB) > 0) {
                        $this->generateLinkAndSendEmail($_POST["username"]);
                        $this->success = "An email was sent to you to reset your password";
                    }else{
                        $this->error = "Could not find the username";
                    }
                }

                if(isset($_POST['password']) === true && isset($_POST['password2']) === true) {

                    if(strlen($_POST['password']) == 0 || $_POST['password'] != $_POST['password2']) {
                        $this->error = "Your passwords do not match";
                    }else{
                        $this->changePW($_POST['password'], $_GET['hash']);
                        $this->success = "Password successfully changed. ";
                    }
                }

            }

            if (isset($_GET['logout'])===true && $_GET['logout']==='1') {

                $this->logout();
                header("Location:".BASE_URL."/");

            }

        }

        public static function getInstance($sessionid="")
        {

            if (self::$instance === null) {

                self::$instance = new self($sessionid);

            }

            return self::$instance;
        }

        /**
         * login - Validate POST-data with DB
         *
         * @access private
         * @return boolean
         */
        private function login()
        {

            if($this->getUserByLogin($this->username, $this->password) === true) {

                //Set Sessions
                $_SESSION['userdata']['role'] = $this->role;
                $_SESSION['userdata']['id'] = $this->userId;
                $_SESSION['userdata']['name'] = $this->name;
                $_SESSION['userdata']['mail'] = $this->mail;
                $_SESSION['userdata']['clientId'] = $this->clientId;
                $_SESSION['userdata']['settings'] = $this->settings;
                $this->updateUserSession($this->session, time());

                $this->setCookie($this->cookieTime);

                return true;

            }else{

                $this->error = 'Username or password incorrect!';

                return false;

            }
        }

        /**
         * setCookie - set and/or updates the cookie
         *
         * @param  $time
         * @return
         */
        private function setCookie($time)
        {
            $expiry = time()+$time;
            setcookie("sid", $this->session, (int)$expiry, "/");
        }

        /**
         * logged_in - Check if logged in and Update sessions
         *
         * @access public
         * @return unknown_type
         */
        public function logged_in()
        {


            try{

                $query = "SELECT count(username) AS userCounter FROM zp_user 
		          WHERE session = :session LIMIT 1";

                $stmn = $this->db->database->prepare($query);

                $stmn->bindValue(':session', $this->session, PDO::PARAM_STR);

                $stmn->execute();

                $returnValues = $stmn->fetch();

            }catch(\PDOException $e){


               return false;

            }

            $userCounter = $returnValues['userCounter'];

            $stmn->closeCursor();

            if($userCounter !=1) {

                $this->logout();

                return false;

            }else{

                if(isset($_COOKIE['sid']) === true) {

                    if(isset($_SESSION['userdata']) === true) {

                        $this->userId = $_SESSION['userdata']['id'];

                        $this->setCookie($this->cookieTime);

                        $this->updateUserSession($this->session, time());

                        return true;

                    }else{

                        $this->logout();

                        return false;

                    }

                }else{

                    $this->logout();

                    return false;

                }

            }
        }

        /**
         * logout - destroy sessions and cookies
         *
         * @access private
         * @return boolean
         */
        private function logout()
        {

            $query = "UPDATE zp_user SET session = '' 
				 WHERE session = :sessionid LIMIT 1";

            $stmn = $this->db->database->prepare($query);

            $stmn->bindValue(':sessionid', $this->session, PDO::PARAM_STR);
            $stmn->execute();
            $stmn->closeCursor();

            $this->setCookie(time()-$this->cookieTime);

            if(isset($_SESSION)) {
                unset($_SESSION['userdata']);
                unset($_SESSION['template']);
                unset($_SESSION["subdomainData"]);
                unset($_SESSION["currentProject"]);
                unset($_SESSION["currentSprint"]);
                unset($_SESSION["projectsettings"]);
                unset($_SESSION['currentSubscription']);
            }

            unset($_COOKIE);

            return true;
        }

        /**
         * checkSessions - check the sesisions in the database and unset them if necessary
         *
         * @access private
         * @return
         */
        private function checkSessions()
        {

            $query = "UPDATE zp_user SET session = '' WHERE (".time()." - sessionTime) > ".$this->cookieTime." ";

            $stmn = $this->db->database->prepare($query);
            $stmn->execute();
            $stmn->closeCursor();

        }



        /**
         * getUserByLogin - Check login data andset email vars
         *
         * @access public
         * @param  $emailname
         * @param  $password
         * @return boolean
         */
        public function getUserByLogin($username, $password)
        {

            $user=$this->getUser($username);

            if($user === false || !password_verify($password, $user['password'])) {

                return false;

            }else{

                //
                $query = "SELECT 
					id,
					username,
					role,
					firstname AS firstname,
					lastname AS name,
					settings,
					profileId,
					clientId
						FROM zp_user 
			          WHERE username = :username
			          LIMIT 1";

                $stmn = $this->db->database->prepare($query);
                $stmn->bindValue(':username', $username, PDO::PARAM_STR);

                $stmn->execute();
                $returnValues = $stmn->fetch();
                $stmn->closeCursor();

                $this->name = strip_tags($returnValues['firstname']);
                $this->mail = filter_var($returnValues['username'], FILTER_SANITIZE_EMAIL);
                $this->userId = $returnValues['id'];
                $this->settings = unserialize($returnValues['settings']);
                $this->clientId = $returnValues['clientId'];


                $roles = self::$userRoles[$returnValues['role']];
                $this->role = self::$userRoles[$returnValues['role']];

                return true;
            }
        }

        /**
         * updateemailSession - Update the sessiontime of a email
         *
         * @access public
         * @param  $sessionid
         * @param  $time
         * @return
         */
        public function updateUserSession($sessionid, $time)
        {
            //echo "updateUserSession";
            $query = "UPDATE
					zp_user 
				SET 
					lastlogin = NOW(),
					session = :sessionid,
					sessionTime = :time 
				WHERE 
					id =  :id 
				LIMIT 1";


            $stmn = $this->db->database->prepare($query);

            $stmn->bindValue(':id', $this->userId, PDO::PARAM_INT);
            $stmn->bindValue(':sessionid', $sessionid, PDO::PARAM_STR);
            $stmn->bindValue(':time', $time, PDO::PARAM_STR);
            $stmn->execute();

            $stmn->closeCursor();
        }

        /**
         * validateResetLink - validates that the password reset link belongs to a user account in the database
         *
         * @access public
         * @param
         * @return bool
         */
        public function validateResetLink()
        {

            $link = stripslashes(htmlentities($_GET["hash"]));

            $query = "SELECT id FROM zp_user WHERE pwReset = :resetLink LIMIT 1";

            $stmn = $this->db->database->prepare($query);
            $stmn->bindValue(':resetLink', $link, PDO::PARAM_STR);

            $stmn->execute();
            $returnValues = $stmn->fetch();
            $stmn->closeCursor();

            if($returnValues !== false && count($returnValues) > 0) {
                return true;
            }else{
                return false;
            }
        }

        /**
         * getUser - gets the user from the db
         *
         * @access public
         * @param  username - string
         * @return bool
         */
        private function getUser($username)
        {

            $query = "SELECT username, password FROM zp_user 
		          WHERE username = :username LIMIT 1";

            $stmn = $this->db->database->prepare($query);
            $stmn->bindValue(':username', $username, PDO::PARAM_STR);

            $stmn->execute();

            $returnValues = $stmn->fetch();

            $stmn->closeCursor();

            return $returnValues;


        }

        private function generateLinkAndSendEmail($username)
        {

            $resetLink = md5($username.$this->session.time());

            $query = "UPDATE
					zp_user 
				SET 
					pwReset = :link,
					pwResetExpiration = :time
				WHERE 
					username = :user
				LIMIT 1";


            $stmn = $this->db->database->prepare($query);
            $stmn->bindValue(':user', $username, PDO::PARAM_STR);
            $stmn->bindValue(':time', date("Y-m-d h:i:s", time()), PDO::PARAM_STR);
            $stmn->bindValue(':link', $resetLink, PDO::PARAM_STR);
            $stmn->execute();
            $count = $stmn->rowCount();
            $stmn->closeCursor();

            if($count > 0) {
                $mailer = new mailer();
                $mailer->setSubject("Leantime Password Reset");
                $actual_link = "".BASE_URL."/resetPassword/".$resetLink;
                $mailer->setHtml("We've received your e-mail requesting your Leantime password be reset.<br /><br />If you would like to reset your password, please click on this link: <br /><a href='".$actual_link."' target='_blank'>Reset Password</a><br /><br />If you did not request a password reset, please ignore this message.<br/><br />Thank you.");
                $to = array($username);
                $mailer->sendMail($to, "Leantime System");
            }

        }

        private function changePW($password, $hash)
        {

            $query = "UPDATE
					zp_user 
				SET 
					password = :password,
					pwReset = '',
					pwResetExpiration = '',
					lastpwd_change = :time
				WHERE 
					pwReset = :hash
				LIMIT 1";


            $stmn = $this->db->database->prepare($query);
            $stmn->bindValue(':time', date("Y-m-d h:i:s", time()), PDO::PARAM_STR);
            $stmn->bindValue(':hash', $hash, PDO::PARAM_STR);
            $stmn->bindValue(':password', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
            $stmn->execute();
            $count = $stmn->rowCount();
            $stmn->closeCursor();



        }

        public static function userIsAtLeast($role) {

            $testKey = array_search($role, self::$userRoles);

            if($role == "" || $testKey === false){
                throw new Exception("Role not defined");
            }

            $currentUserKey = array_search($_SESSION['userdata']['role'], self::$userRoles);

            if($testKey <= $currentUserKey){
                return true;
            }else{
                return false;
            }
        }


        public static function userHasRole ($role) {

            if($role == $_SESSION['userdata']['role']){
                return true;
            }

            return false;

        }

        public static function getRole () {

        }

        public static function getUserClientId () {
            return $_SESSION['userdata']['clientId'];
        }


        public static function getUserId () {
            return $_SESSION['userdata']['id'];
        }


    }
}