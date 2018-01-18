<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
// use Symfony\Component\HttpFoundation\Request;
use RedBeanPHP\R;

class Admin extends Backend
{

  /**
  * @Route("/admin/respondents/{questionnaire_id}/{page}/{sort_by}/{sorting}", name="admin_respondents", requirements={"questionnaire_id"="\d+", "page"="\d+", "sort_by": "[a-zA-Z0-9_]+", "sorting": "asc|desc"})
  */
    public function admin_respondents($questionnaire_id = 1, $page = 1, $sort_by = 'ts_started', $sorting = 'desc')
    {
        $this->admin_auth();

        $people = $this->respondents_browse($questionnaire_id, $page, $sort_by, $sorting);

        return $this->render('admin/table-admin.html.twig', array(
    'items' => $people,
    'pagination' => $this->pagination
    ));
    }

    /**
    * @Route("/build/respondents/{questionnaire_id}/{page}/{sort_by}/{sorting}", name="browse_respondents", requirements={"questionnaire_id"="\d+", "page"="\d+", "sort_by": "[a-zA-Z0-9_]+", "sorting": "asc|desc"})
    */
    public function browse_respondents($questionnaire_id = 1, $page = 1, $sort_by = 'ts_started', $sorting = 'desc')
    {
        if (!$this->member_auth(false)) {
            $this->admin_auth(true);
        }

        $people = $this->respondents_browse($questionnaire_id, $page, $sort_by, $sorting);

        return $this->render('admin/table-members.html.twig', array(
      'items' => $people,
      'pagination' => $this->pagination
  ));
    }


    public function member_get($u)
    {
        $u = new class {
        };

        $username = $this->username_by_respondent_id($this->respondent->id);

        if (!$u->username) {
            $u->error .= "Could not find the username. ";
        }

        // $u->username = $username;
        // $u->email = $this->respondent->email;
        // $u->status = $this->respondent->status;

        if ($u->status=="invite") {
            $pw = bin2hex(openssl_random_pseudo_bytes(4));
            $u->password_random = $pw;
        }

        return $u;
    }

    public function member_update()
    {
        $email = $_REQUEST['email'];
        $status = $_REQUEST['status'];
        $ret = new class {
        };

        if ($email) {
            $this->respondent = $this->respondent_find($email);
        }

        $ret = $this->respondent;

        if (!$this->respondent) {
            $ret->error = "No such member found. ";
        } elseif ($status) { // update status

            if ($status=='created') {
                $ret->account_email_sent = false;

                $pw = $_REQUEST['password'];

                $username = $this->username_by_respondent_id($this->respondent->id);

                if (!$username) {
                    $ret->error .= "Could not find the username. ";
                }

                if ($pw && $username) {
                    if ($this->send_account_email($email, $username, $pw)) {
                        $ret->account_email_sent = true;
                    } else {
                        $ret->error .= "Error trying to send confirmation email (no custom email function). ";
                    }
                } else {
                    $ret->error .= "The confirmation email could not be sent. (Make sure you include the new account's password in the request). ";
                }
            }

            $this->respondent->status = $status;
            $ret->updated = R::store($this->respondent);
        }

        return $ret;
    }

    public function respondents_browse($questionnaire_id, $page, $sort_by, $sorting)
    {
        $this->questionnaire_id = $questionnaire_id ? $questionnaire_id : $this->session->get('questionnaire'); // get from session

        // R::debug();

        $count = R::count('respondent', ' questionnaire_id = ? AND email IS NOT NULL ', [ $this->questionnaire_id ]);

        if (!$count) {
            return [];
        }

        $this->session->set('questionnaire', $this->questionnaire_id); // save as session

        $limits = ($this->conf->db_type == 'postgres' ? ' LIMIT ? OFFSET ? ' : ' LIMIT ? , ? ');

        $per_page = 50;
        // if ($this->conf->db_type == 'postgres') { // TODO
        //     $params = [ $this->questionnaire_id, $per_page, $per_page*($page-1) ];
        // } else {
        //     $params = [ $this->questionnaire_id, $per_page*($page-1), $per_page ];
        // }

        // $people = R::find('respondent', " questionnaire_id = ? AND email IS NOT NULL ORDER BY $sort_by $sorting ".$limits, $params); // with hard limits
        $people = R::find('respondent', " questionnaire_id = ? AND email IS NOT NULL ORDER BY $sort_by $sorting ", [ $this->questionnaire_id ]); // list all

        if ($people) {
            $paginator  = $this->get('knp_paginator');
            $people = $paginator->paginate(
              $people, /* ideally query NOT result */
              $page/*page number*/,
              $per_page/*limit per page*/
          );


            foreach ($people as $p) {
                $responses = R::find('response', ' respondent_id = ?
		          ORDER BY response_ts ASC', [ $p->id ]);

                foreach ($responses as $r) {
                    echo '<p>';
                    $c = $r->the_var ? $r->the_var : $r->answer->answer;

                    $f = $r->question->question_name;

                    if ($f) {
                        if ($f !='username' && $p->{$f} && $p->{$f} !=$c) {
                            $p->{$f} .= ' ;<br> '  . $c;
                        } else {
                            $p->{$f} = $c;
                        }
                    }

                    unset($f, $c, $q_ok, $qid);
                }

                if ($p->mastodon_id==1) {
                    $p->status = 'probation';
                }

                if ($p->status=='probation') {
                    $p->status_class = 'info';
                } elseif ($p->status=='invite') {
                    $p->status_class = 'warning';
                } elseif ($p->status=='full') {
                    $p->status_class = 'success';
                }
            }
            return $people;
        }
    }


    /**
    * @Route("/admin/respondent/{respondent_id}/status/{status}", name="admin_status_set", requirements={"respondent_id"="\d+"} )
    */
    public function admin_status_set($respondent_id, $status)
    {
        $this->admin_auth();

        $this->respondent = $this->data_by_id('respondent', $respondent_id);

        if (!$this->respondent) {
            exit('respondent not found');
        }

        $r = $this->response_by_question_id($this->conf->question_id_username, $this->respondent->id); // get username

        if (!$r) {
            $r = $this->response_by_question_id($this->conf->question_id_name, $this->respondent->id);
        } // otherwise get name

        if (!$r) {
            exit('username not found');
        }

        $uname = $r->the_var ? $r->the_var : $r->answer->answer;

        if (!$uname) {
            exit('no username found');
        }

        $uname_ok = $this->sanitize_string($uname);

        if (!$uname_ok) {
            exit('username not valid');
        }

        $this->question = $this->question_get($this->conf->question_id_username); // needed by response_save() for saving sanitized username
        if (!$this->question) {
            exit('username not found in DB');
        }

        $respond['theVar'] = $uname_ok; // store
        $response_ids[] = $this->response_save($respond);

        $this->respondent->status = $status;
        R::store($this->respondent);

        exit("An account will be created with username: ".$uname_ok);
    }

    public function send_account_email($email, $username, $pw)
    {
        global $bv;

        $custom_member_inc = $this->conf->base_path."custom/config_invite.php";
        if (file_exists($custom_member_inc)) {
            include_once($custom_member_inc);
        }

        return email_send($bv->message_welcome_body, $email, $bv->message_welcome_subject);
    }
}
