<?php

class PaymentsController extends AppController {

    var $name = 'Payments';
    var $components = array('Security', 'Cakepal');
    var $uses = array('Payment');
    var $allowedPaymentTypes = array('Paypal'); #obviously plan to change this eventually
    var $menuOptions = array('exclude' => array('expresspay', 'forceSSL', 'refund'));
    function beforeFilter() {
        $this->Security->blackHoleCallback = 'forceSSL';
        //   $this->Security->requireSecure('choosePaymentMethod', 'expresspay', 'refund');
    }

    function forceSSL() {
        $this->redirect('https://' . $_SERVER['SERVER_NAME'] . $this->here);
    }

    function index() {
        $this->Payment->recursive = 0;
        $this->set('payments', $this->paginate());
    }

    function view($id = null) {
        if (!$id) {
            $this->Session->setFlash(__('Invalid payment', true));
            $this->redirect(array('action' => 'index'));
        }
        $this->set('payment', $this->Payment->read(null, $id));
    }

    function add($booking = null) {
        // If there is data to be saved, do it
        xdebug_break();
        if (!empty($this->data)) {
            $this->Payment->create();
            if (!(($this->Payment->save($this->data)) === false)) {
                $this->Session->setFlash(__('The payment has been saved', true));
                $book = $this->Payment->Booking->find('first', array(
                    'conditions'=> array('Booking.id' => $this->data['Payment']['booking_id'])));
                if($book['Booking']['international']== true){
                    $count = count($book['Passenger']);
                    $pax = $book['Booking']['num_adults'] + $booking['Booking']['num_children'];
                    if($count < $pax){
                        $this->redirect(array('controller' => 'profiles', 'action' => 'add', 'pax' => $pax, 'bid' => $book['Booking']['id']));
                }}
            } else {
                $this->Session->setFlash(__('The payment could not be saved. Please, try again.', true));
            }
        } else {
            //check the session for data
            $booking = $this->Session->read('booking');
            if (!isset($booking['saved'])&& isset($booking['prices'])) {
                $bid =$this->Payment->Booking->saveBooking($booking, $this->Auth->user('id'));
                $this->Session->write('booking.saved', 'true');
                $this->Session->write('bid', $bid);
            }
        }
        // if we have a saved booking to work with, set the appropriate bid & total
        if(!empty($bid)){
            $id = $bid;
        } elseif(!empty($this->params['named']['booking'])){
            $id = $this->params['named']['booking'];
        } else {
            $id = false;
        }
        if($id){ 
            $this->set('bid', $id);
            $total = $this->Payment->Booking->field('totalcost', array('Booking.id' => $id));
            $this->set('total', $total);
            }
        $bookings = $this->Payment->Booking->find('list');
        $this->set(compact('bookings'));
    }

    function edit($id = null) {
        if (!$id && empty($this->data)) {
            $this->Session->setFlash(__('Invalid payment', true));
            $this->redirect(array('action' => 'index'));
        }
        if (!empty($this->data)) {
            if ($this->Payment->save($this->data)) {
                $this->Session->setFlash(__('The payment has been saved', true));
                $this->redirect(array('action' => 'index'));
            } else {
                $this->Session->setFlash(__('The payment could not be saved. Please, try again.', true));
            }
        }
        if (empty($this->data)) {
            $this->data = $this->Payment->read(null, $id);
        }
        $bookings = $this->Payment->Booking->find('list');
        $this->set(compact('bookings'));
    }

    function delete($id = null) {
        if (!$id) {
            $this->Session->setFlash(__('Invalid id for payment', true));
            $this->redirect(array('action' => 'index'));
        }
        if ($this->Payment->delete($id)) {
            $this->Session->setFlash(__('Payment deleted', true));
            $this->redirect(array('action' => 'index'));
        }
        $this->Session->setFlash(__('Payment was not deleted', true));
        $this->redirect(array('action' => 'index'));
    }

    function refund($pid = null) {
        if (!$pid) {
            $this->Session->setFlash(__('Invalid Payment', true));
            $this->redirect(array('action' => 'index'));
        }
        if (!empty($this->data)) {
            $payment_id = $this->data['Payment']['id'];
            $refundType = $this->data['refund_type'];
            $amount = ($refundType != 'FULL') ? $this->data['amount'] : null;
            $refund = $this->Payment->refund($payment_id, $refundType, $amount);
            if (!is_array($refund)) {
                $this->cakeError('refundError', "$refundType Refund for Payment #$payment_id failed!");
            } else {
                $user = $this->Auth->user();
                CakeLog::write('Activity', "$refundType issued for Payment #$payment_id by" . $user['username']);
                $this->Session->setFlash(__('Refund Successful', true));
                $this->redirect(array('action' => 'index'));
            }
        }

        $bookings = $this->Payment->Booking->find('list');
        $this->set(compact('bookings'));
    }

    function expresspay($bid, $step = null) {


        if (!isset($step)) {
            if ($bid) {
                $booking = $this->Payment->Booking->find('first', array(
                    'conditions' => array('Booking.id' => $bid)
                        ));
                $items = $this->Payment->Booking->describeItems($booking);
                $response = $this->Cakepal->expresspay($bid, $items, $booking['Booking']['totalcost']);
                $ack = $response->getAck();
                if ($ack == 'Success' || 'SuccessWithWarning') {
                    $pp_url = $this->Cakepal->config['url'] . $response->getToken();
                    $this->redirect($pp_url);
                }
            }
        } elseif ($step == 'confirm') {
            // confirm which payment exactly?
            $token = $this->params['url']['data']['Token'];
            // the above is probably not correct
            $result = $this->Cakepal->getExpressDetails($token);
            if ($result) {
                extract($result);
                $this->set('OrderTotal', $OrderTotal);
                $b = $this->Payment->Booking->find('first', array(
                    'conditions' => array('id' => $invoice_id),
                    'recursive' => '2'
                        ));
                $this->set('Booking', $b);
                $this->set('PayerID', $payer_id);
            } else {
                // wtf? how did you get here?
                $this->cakeError('PayPalError', 'Token not valid');
            }
        } elseif ($step == 'finalize') {
            if ($this->data) {
                //  here we need to save any related data collected OR set a notification about it not being there
                $token = $this->data['Payment']['Token'];
                $payer = $this->data['Payment']['payer_id'];
                $total = $this->data['Payment']['total'];
                if ($this->data['Booking']['international'] == true) {
                    if (!empty($this->data['Passenger'])) {
                        $pax['Passenger'] = $this->data['Passenger'];
                        $this->Payment->Booking->Passenger->saveAll($pax);
                    } else {
                        // set notification flag somehow
                    }
                }
                $transaction = $this->Cakepal->doExpressPayment($token, $payer, $total);
                if ($transaction) {
                    $payment = array(
                        'transaction_id' => $transaction,
                        'type' => 'Payment',
                        'channel' => 'PayPal',
                        'amount' => $total,
                        'booking_id' => $this->data['Booking']['id']
                    );
                    $this->Payment->save($payment);
                    // the following line should be replaced with an observer
                    $this->Payment->Booking->setFlag($this->data['Booking']['id'], 'paid', true);
                    $this->redirect(array('controller' => 'bookings', 'action' => 'confirmBooking', 'booking' => $this->data['Booking'][id]));
                }
            } else {
                $this->Session->setFlash('Something went wrong. Notify an administrator.');
                $this->redirect(array('action' => 'choosePaymentMethod'));
            }
        }
    }

    function choosePaymentMethod() {
        // allow the user to pay for any outstanding bookings
        $booking = $this->Session->read('booking');
        // find all unpaid bookings
        $unpaid = $this->Payment->Booking->User->findUnpaid($this->Auth->user('id'));
        if ($booking) {
            // it's probably appropriate to save it at this point
            if ($this->Session->read('booking.saved') == false) {
                $bid = $this->Payment->Booking->saveBooking($booking, $this->Auth->user('id'));
                if ($bid === false) {
                    $this->setFlash('Error: Could Not Save Booking');
                } else {
                    $this->Session->write('booking.saved', true);
                    $newbooking = $this->Payment->Booking->find('first', array(
                        'conditions' => array('Booking.id' => $bid),
                        'recursive' => '2'
                            ));
                    array_push($unpaid, $newbooking);
                }
            }
        }
        $this->set('unpaid', $unpaid);
        $this->set('allowedPaymentTypes', $this->allowedPaymentTypes);
    }

}

?>